<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Command;

use RomainGenin\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use RomainGenin\ImageOptimizerBundle\Upload\UploadedFileOptimizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'image-optimizer:optimize', description: 'Losslessly optimizes existing images (files or directories, recursively)')]
final class OptimizeImagesCommand extends Command
{
    private const EXTENSIONS = '/\.(jpe?g|jfif|png|gif|webp|bmp|tiff?|svg)$/i';

    public function __construct(private readonly ImageOptimizerInterface $optimizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Files or directories')
            ->addOption('convert', null, InputOption::VALUE_NONE, 'Also convert formats losslessly (e.g. PNG -> WebP). Files get renamed: update your database references!')
            ->setHelp(<<<'HELP'
                Optimizes images already stored on disk with the same lossless pipeline used for uploads.

                  <info>php %command.full_name% public/uploads</info>

                Without <comment>--convert</comment>, files keep their format and name, so nothing else needs to change.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $convert = (bool) $input->getOption('convert');
        $files = [];

        foreach ($input->getArgument('paths') as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                foreach ((new Finder())->files()->in($path)->name(self::EXTENSIONS) as $file) {
                    $files[] = $file->getPathname();
                }
            } else {
                $io->warning(sprintf('"%s" does not exist.', $path));
            }
        }

        $before = $after = $optimized = 0;
        $io->progressStart(\count($files));

        foreach ($files as $file) {
            $result = $this->optimizer->optimize($file, $convert);
            $before += $result->originalSize;
            $after += $result->size;

            if ($result->isOptimized()) {
                ++$optimized;
            }

            if ($result->isConverted() && null !== $result->format) {
                $renamed = \dirname($file) . '/' . UploadedFileOptimizer::replaceExtension(basename($file), $result->format->extension());
                if (!file_exists($renamed) && rename($file, $renamed)) {
                    $io->writeln(sprintf(' %s -> %s', $file, $renamed), OutputInterface::VERBOSITY_VERBOSE);
                } else {
                    $io->warning(sprintf('"%s" now contains %s data but could not be renamed to "%s".', $file, $result->format->value, $renamed));
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf(
            '%d/%d images optimized: %s -> %s (-%s)',
            $optimized,
            \count($files),
            Helper::formatMemory($before),
            Helper::formatMemory($after),
            $before > 0 ? round(($before - $after) / $before * 100, 1) . '%' : '0%',
        ));

        return Command::SUCCESS;
    }
}
