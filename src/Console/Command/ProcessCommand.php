<?php

declare(strict_types=1);

namespace Rector\Core\Console\Command;

use Rector\Caching\ChangedFilesDetector;
use Rector\Caching\UnchangedFilesFilter;
use Rector\ChangesReporting\Application\ErrorAndDiffCollector;
use Rector\ChangesReporting\Output\ConsoleOutputFormatter;
use Rector\Core\Application\RectorApplication;
use Rector\Core\Autoloading\AdditionalAutoloader;
use Rector\Core\Configuration\Configuration;
use Rector\Core\Configuration\Option;
use Rector\Core\Console\Output\OutputFormatterCollector;
use Rector\Core\Console\Shell;
use Rector\Core\Extension\ReportingExtensionRunner;
use Rector\Core\FileSystem\FilesFinder;
use Rector\Core\Guard\RectorGuard;
use Rector\Core\PhpParser\NodeTraverser\RectorNodeTraverser;
use Rector\Core\Stubs\StubLoader;
use Rector\Core\Yaml\YamlProcessor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symplify\PackageBuilder\Console\Command\CommandNaming;

final class ProcessCommand extends AbstractCommand
{
    /**
     * @var string[]
     */
    private $fileExtensions = [];

    /**
     * @var string[]
     */
    private $paths = [];

    /**
     * @var FilesFinder
     */
    private $filesFinder;

    /**
     * @var AdditionalAutoloader
     */
    private $additionalAutoloader;

    /**
     * @var RectorGuard
     */
    private $rectorGuard;

    /**
     * @var ErrorAndDiffCollector
     */
    private $errorAndDiffCollector;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var RectorApplication
     */
    private $rectorApplication;

    /**
     * @var OutputFormatterCollector
     */
    private $outputFormatterCollector;

    /**
     * @var ReportingExtensionRunner
     */
    private $reportingExtensionRunner;

    /**
     * @var RectorNodeTraverser
     */
    private $rectorNodeTraverser;

    /**
     * @var StubLoader
     */
    private $stubLoader;

    /**
     * @var YamlProcessor
     */
    private $yamlProcessor;

    /**
     * @var UnchangedFilesFilter
     */
    private $unchangedFilesFilter;

    /**
     * @var ChangedFilesDetector
     */
    private $changedFilesDetector;

    /**
     * @param string[] $paths
     * @param string[] $fileExtensions
     */
    public function __construct(
        FilesFinder $phpFilesFinder,
        AdditionalAutoloader $additionalAutoloader,
        RectorGuard $rectorGuard,
        ErrorAndDiffCollector $errorAndDiffCollector,
        Configuration $configuration,
        RectorApplication $rectorApplication,
        OutputFormatterCollector $outputFormatterCollector,
        ReportingExtensionRunner $reportingExtensionRunner,
        RectorNodeTraverser $rectorNodeTraverser,
        StubLoader $stubLoader,
        YamlProcessor $yamlProcessor,
        ChangedFilesDetector $changedFilesDetector,
        UnchangedFilesFilter $unchangedFilesFilter,
        array $paths,
        array $fileExtensions
    ) {
        $this->filesFinder = $phpFilesFinder;
        $this->additionalAutoloader = $additionalAutoloader;
        $this->rectorGuard = $rectorGuard;
        $this->errorAndDiffCollector = $errorAndDiffCollector;
        $this->configuration = $configuration;
        $this->rectorApplication = $rectorApplication;
        $this->fileExtensions = $fileExtensions;
        $this->outputFormatterCollector = $outputFormatterCollector;
        $this->reportingExtensionRunner = $reportingExtensionRunner;
        $this->rectorNodeTraverser = $rectorNodeTraverser;
        $this->stubLoader = $stubLoader;
        $this->paths = $paths;
        $this->yamlProcessor = $yamlProcessor;
        $this->unchangedFilesFilter = $unchangedFilesFilter;

        parent::__construct();
        $this->changedFilesDetector = $changedFilesDetector;
    }

    protected function configure(): void
    {
        $this->setName(CommandNaming::classToName(self::class));
        $this->setDescription('Upgrade or refactor source code with provided rectors');
        $this->addArgument(
            Option::SOURCE,
            InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
            'Files or directories to be upgraded.'
        );
        $this->addOption(
            Option::OPTION_DRY_RUN,
            'n',
            InputOption::VALUE_NONE,
            'See diff of changes, do not save them to files.'
        );

        $this->addOption(
            Option::OPTION_AUTOLOAD_FILE,
            'a',
            InputOption::VALUE_REQUIRED,
            'File with extra autoload'
        );

        $this->addOption(
            Option::HIDE_AUTOLOAD_ERRORS,
            'e',
            InputOption::VALUE_NONE,
            'Hide autoload errors for the moment.'
        );

        $this->addOption(
            Option::MATCH_GIT_DIFF,
            null,
            InputOption::VALUE_NONE,
            'Execute only on file(s) matching the git diff.'
        );

        $this->addOption(
            Option::OPTION_ONLY,
            'r',
            InputOption::VALUE_REQUIRED,
            'Run only one single Rector from the loaded Rectors (in services, sets, etc).'
        );

        $availableOutputFormatters = $this->outputFormatterCollector->getNames();
        $this->addOption(
            Option::OPTION_OUTPUT_FORMAT,
            'o',
            InputOption::VALUE_OPTIONAL,
            sprintf('Select output format: "%s".', implode('", "', $availableOutputFormatters)),
            ConsoleOutputFormatter::NAME
        );

        $this->addOption(
            Option::OPTION_NO_PROGRESS_BAR,
            null,
            InputOption::VALUE_NONE,
            'Hide progress bar. Useful e.g. for nicer CI output.'
        );

        $this->addOption(
            Option::OPTION_OUTPUT_FILE,
            null,
            InputOption::VALUE_REQUIRED,
            'Location for file to dump result in. Useful for Docker or automated processes'
        );

        $this->addOption(Option::OPTION_CLEAR_CACHE, null, InputOption::VALUE_NONE, 'Clear unchaged files cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->configuration->resolveFromInput($input);
        $this->configuration->setAreAnyPhpRectorsLoaded((bool) $this->rectorNodeTraverser->getPhpRectorCount());

        $this->rectorGuard->ensureSomeRectorsAreRegistered();
        $this->rectorGuard->ensureGetNodeTypesAreNodes();
        $this->stubLoader->loadStubs();

        $source = $this->resolvesSourcePaths($input);
        $this->configuration->setSource($source);

        $phpFileInfos = $this->filesFinder->findInDirectoriesAndFiles(
            $source,
            $this->fileExtensions,
            $this->configuration->mustMatchGitDiff()
        );

        $this->additionalAutoloader->autoloadWithInputAndSource($input, $source);

        // cache stuff
        if ($this->configuration->shouldClearCache()) {
            $this->changedFilesDetector->clear();
        }

        $phpFileInfos = $this->unchangedFilesFilter->filterAndJoinWithDependentFileInfos($phpFileInfos);

        // yaml
        $this->yamlProcessor->run();

        $this->configuration->setFileInfos($phpFileInfos);
        $this->rectorApplication->runOnFileInfos($phpFileInfos);

        // report diffs and errors
        $outputFormat = (string) $input->getOption(Option::OPTION_OUTPUT_FORMAT);
        $outputFormatter = $this->outputFormatterCollector->getByName($outputFormat);
        $outputFormatter->report($this->errorAndDiffCollector);

        $this->reportingExtensionRunner->run();

        // invalidate affected files
        foreach ($this->errorAndDiffCollector->getAffectedFileInfos() as $affectedFileInfo) {
            $this->changedFilesDetector->invalidateFile($affectedFileInfo);
        }

        // some errors were found → fail
        if ($this->errorAndDiffCollector->getErrors() !== []) {
            return Shell::CODE_ERROR;
        }

        // inverse error code for CI dry-run
        if ($this->configuration->isDryRun() && $this->errorAndDiffCollector->getFileDiffsCount()) {
            return Shell::CODE_ERROR;
        }

        return Shell::CODE_SUCCESS;
    }

    /**
     * @return string[]
     */
    private function resolvesSourcePaths(InputInterface $input): array
    {
        $commandLinePaths = (array) $input->getArgument(Option::SOURCE);

        // manual command line value has priority
        if (count($commandLinePaths) > 0) {
            return $commandLinePaths;
        }

        // fallback to config defined paths
        return $this->paths;
    }
}
