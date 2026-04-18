<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Command;

use Episciences\GrobidClient\Exception\ServerUnavailableException;
use Episciences\GrobidClient\GrobidClient;
use Episciences\GrobidClient\GrobidConfig;
use Episciences\GrobidClient\GrobidService;
use Episciences\GrobidClient\ProcessingOptions;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'grobid:process',
    description: 'Process documents with GROBID',
)]
final class ProcessCommand extends Command
{
    private const SERVICES = [
        'processFulltextDocument',
        'processHeaderDocument',
        'processReferences',
        'processCitationList',
        'processCitationPatentST36',
        'processCitationPatentPDF',
    ];

    /**
     * @param GrobidClient|null $client Pre-built client. When null, built from CLI options at runtime.
     *                                  Inject to bypass server check (useful in tests or DI containers).
     */
    public function __construct(private readonly ?GrobidClient $client = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'service',
                InputArgument::REQUIRED,
                sprintf('GROBID service to use. One of: %s', implode(', ', self::SERVICES))
            )
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Input file or directory path')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory path')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to config.json file')
            ->addOption('server', 's', InputOption::VALUE_REQUIRED, 'GROBID server URL (overrides config)')
            ->addOption('n', null, InputOption::VALUE_REQUIRED, 'Number of concurrent requests', '10')
            ->addOption('generate-ids', null, InputOption::VALUE_NONE, 'Generate XML IDs for structures')
            ->addOption('consolidate-header', null, InputOption::VALUE_REQUIRED, 'Consolidate header (0, 1 or 2)', '1')
            ->addOption('consolidate-citations', null, InputOption::VALUE_REQUIRED, 'Consolidate citations (0, 1 or 2)', '0')
            ->addOption('include-raw-citations', null, InputOption::VALUE_NONE, 'Include raw citation strings in output')
            ->addOption('include-raw-affiliations', null, InputOption::VALUE_NONE, 'Include raw affiliation strings in output')
            ->addOption('tei-coordinates', null, InputOption::VALUE_NONE, 'Add bounding box coordinates in PDF')
            ->addOption('segment-sentences', null, InputOption::VALUE_NONE, 'Segment body text into sentences')
            ->addOption('no-force', null, InputOption::VALUE_NONE, 'Skip files whose output already exists')
            ->addOption('flavor', null, InputOption::VALUE_REQUIRED, 'GROBID flavor')
            ->addOption('start-page', null, InputOption::VALUE_REQUIRED, 'First page to process', '-1')
            ->addOption('end-page', null, InputOption::VALUE_REQUIRED, 'Last page to process', '-1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serviceValue = $input->getArgument('service');
        if (!is_string($serviceValue)) {
            $output->writeln('<error>Invalid service argument.</error>');
            return Command::FAILURE;
        }

        $service = GrobidService::tryFrom($serviceValue);
        if ($service === null) {
            $output->writeln(sprintf(
                '<error>Unknown service "%s". Valid services: %s</error>',
                $serviceValue,
                implode(', ', self::SERVICES)
            ));
            return Command::FAILURE;
        }

        $inputPath = $input->getOption('input');
        if (!is_string($inputPath) || $inputPath === '') {
            $output->writeln('<error>--input is required.</error>');
            return Command::FAILURE;
        }

        $logger = new ConsoleLogger($output, [
            LogLevel::DEBUG   => OutputInterface::VERBOSITY_VERY_VERBOSE,
            LogLevel::INFO    => OutputInterface::VERBOSITY_VERBOSE,
            LogLevel::NOTICE  => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::WARNING => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::ERROR   => OutputInterface::VERBOSITY_NORMAL,
        ]);

        $client = $this->client;

        if ($client === null) {
            $configPath = $input->getOption('config');
            $config = is_string($configPath) && $configPath !== ''
                ? GrobidConfig::fromFile($configPath)
                : new GrobidConfig();

            $serverUrl = $input->getOption('server');
            if (is_string($serverUrl) && $serverUrl !== '') {
                $config = $config->withOverrides(grobidServer: $serverUrl);
            }

            try {
                $client = new GrobidClient($config, checkServer: true, logger: $logger);
            } catch (ServerUnavailableException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        $outputPath = $input->getOption('output');

        $options = new ProcessingOptions(
            outputPath:             is_string($outputPath) && $outputPath !== '' ? $outputPath : null,
            concurrency:            (int) $input->getOption('n'),
            generateIds:            (bool) $input->getOption('generate-ids'),
            consolidateHeader:      (int) $input->getOption('consolidate-header'),
            consolidateCitations:   (int) $input->getOption('consolidate-citations'),
            includeRawCitations:    (bool) $input->getOption('include-raw-citations'),
            includeRawAffiliations: (bool) $input->getOption('include-raw-affiliations'),
            teiCoordinates:         (bool) $input->getOption('tei-coordinates'),
            segmentSentences:       (bool) $input->getOption('segment-sentences'),
            force:                  !(bool) $input->getOption('no-force'),
            flavor:                 is_string($input->getOption('flavor')) && $input->getOption('flavor') !== ''
                ? $input->getOption('flavor')
                : null,
            startPage:              (int) $input->getOption('start-page'),
            endPage:                (int) $input->getOption('end-page'),
        );

        $start = microtime(true);

        try {
            $result = $client->process($service, $inputPath, $options);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        $output->writeln(sprintf(
            "\n<info>Done in %ss — processed: %d, errors: %d, skipped: %d</info>",
            $elapsed,
            $result->processed,
            $result->errors,
            $result->skipped,
        ));

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }
}
