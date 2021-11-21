<?php

namespace App\Console\Commands;

use App\Objects\GuesserFiles;
use App\Objects\ReportExecution;
use App\Objects\WorkflowGenerator;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GenerateWorkflow extends Command
{
    private bool $saveFile;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ghygen:generate
    {--projectdir= : the directory of the project with composer.json}
    {--cache : enable caching packages in the workflow}
    {--envfile=' . GuesserFiles::ENV_DEFAULT_TEMPLATE_FILE . ' : the .env file to use in the workflow}
    {--prefer-stable : Prefer stable versions of dependencies}
    {--prefer-lowest : Prefer lowest versions of dependencies}
    {--save= : the yaml file to save the workflow}
    ';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate GitHub Actions workflow automatically from
    a project (repository, local filesystem) using composer.json, .env and package.json';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function title(string $title): void
    {
        $this->line(str_pad("", strlen($title) + 12, "*"), "info");
        $this->line("***   " . $title . "   ***", "info");
        $this->line(str_pad("", strlen($title) + 12, "*"), "info");
        $this->newLine();
        $this->line("Auto detecting characteristics of your project");
        $this->line("To generate a GitHub Actions workflow");
        $this->newLine();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $reportExecution = new ReportExecution();
        $this->saveFile = false;
        $projectdir = $this->option("projectdir");
        if (is_null($projectdir)) {
            $projectdir = "";
        } else {
            $projectdir = rtrim($projectdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        $yamlFile = $this->option("save");
        $this->saveFile = ! is_null($yamlFile);
        if ($this->saveFile) {
            if ($yamlFile === "auto") {
                $yamlFile = GuesserFiles::generateYamlFilename(GuesserFiles::getGithubWorkflowDirectory($projectdir));
            }
            if (file_exists($yamlFile)) {
                $this->alert("File " . $yamlFile . " exists");
                return;
            }
        }

        $cache = $this->option("cache");
        $optionEnvWorkflowFile = $this->option("envfile");

        $guesserFiles = new GuesserFiles();
        $guesserFiles->pathFiles($projectdir, $optionEnvWorkflowFile);

        //$this->line("Composer : " . $guesserFiles->getComposerPath());
        if (! $guesserFiles->composerExists()) {
            $this->error("Composer file not found");
            return -1;
        }

        $generator = new WorkflowGenerator();
        $generator->loadDefaults();

        if ($guesserFiles->composerExists() === true) {
            $reportExecution->addInfo("Composer file", "Loaded");
            $composer = json_decode(file_get_contents($guesserFiles->getComposerPath()), true);
            $generator->name = Arr::get($composer, 'name', "");
            $reportExecution->addInfo("Project name", $generator->name);
            $yamlFile = GuesserFiles::generateYamlFilename(
                GuesserFiles::getGithubWorkflowDirectory($projectdir),
                $generator->name
            );

            $phpversion = Arr::get($composer, 'require.php', "8.0");

            $stepPhp = $generator->detectPhpVersion($phpversion);
            $reportExecution->addInfo("PHP versions", $stepPhp);

            if ($this->option("prefer-stable") && $this->option("prefer-lowest")) {
                $generator->dependencyStability = [ 'prefer-stable', 'prefer-lowest' ];
            } elseif ($this->option("prefer-lowest")) {
                $generator->dependencyStability = [ 'prefer-lowest' ];
            } elseif ($this->option("prefer-stable")) {
                $generator->dependencyStability = [ 'prefer-stable' ];
            } else {
                $generator->dependencyStability = [ 'prefer-none' ];
            }
            $reportExecution->addInfo("Dependency stability", $generator->dependencyStability);
            // detect packages
            $devPackages = Arr::get($composer, 'require-dev');
            // testbench
            $testbenchVersions = Arr::get($devPackages, "orchestra/testbench", "");
            if ($testbenchVersions !== "") {
                $laravelVersions = GuesserFiles::detectLaravelVersionFromTestbench($testbenchVersions);
                $generator->matrixLaravel = true;
                $generator->matrixLaravelVersions = $laravelVersions;
                $reportExecution->addInfo("Laravel versions", $laravelVersions);
            }
            // squizlabs/php_codesniffer
            $phpCodesniffer = Arr::get($devPackages, "squizlabs/php_codesniffer", "");
            if ($phpCodesniffer !== "") {
                $generator->stepExecuteCodeSniffer = true;
                $generator->stepInstallCodeSniffer = false;
                $reportExecution->addInfo("Code sniffer", "Install");
            }
            // nunomaduro/larastan
            $larastan = Arr::get($devPackages, "nunomaduro/larastan", "");
            if ($larastan !== "") {
                $generator->stepExecuteStaticAnalysis = true;
                $generator->stepInstallStaticAnalysis = false;
                $generator->stepToolStaticAnalysis = "larastan";
                $generator->stepPhpstanUseNeon = $guesserFiles->phpstanNeonExists();
                $reportExecution->addInfo("Static code analysis", "Larastan and PHPStan");
            } else {
                $phpstan = Arr::get($devPackages, "phpstan/phpstan", "");
                if ($phpstan !== "") {
                    $generator->stepExecuteStaticAnalysis = true;
                    $generator->stepInstallStaticAnalysis = false;
                    $generator->stepToolStaticAnalysis = "phpstan";
                    $generator->stepPhpstanUseNeon = $guesserFiles->phpstanNeonExists();
                    $reportExecution->addInfo("Static code analysis", "PHPStan");
                }
            }
            $generator->stepDusk = false;
            // phpunit/phpunit
            $generator->stepExecutePhpunit = false;
            $phpunit = Arr::get($devPackages, "phpunit/phpunit", "");
            if ($phpunit !== "") {
                $generator->stepExecutePhpunit = true;
                $reportExecution->addInfo("Automated test", "PHPUnit");
            }
            // phpunit/phpunit
            $generator->stepExecutePestphp = false;
            $pestphp = Arr::get($devPackages, "pestphp/pest", "");
            if ($pestphp !== "") {
                $generator->stepExecutePestphp = true;
                $reportExecution->addInfo("Automated test", "Pest");
            }
        }
        $generator->detectCache($cache);

        $generator->databaseType = WorkflowGenerator::DB_TYPE_NONE;
        $generator->stepRunMigrations = false;

        if ($guesserFiles->envExists()) {
            $envArray = $generator->readDotEnv($guesserFiles->getEnvPath());
            $databaseType = Arr::get($envArray, "DB_CONNECTION", "");


            $generator->databaseType = WorkflowGenerator::DB_TYPE_NONE;
            $generator->stepRunMigrations = false;
            if ($databaseType === "mysql") {
                $generator->databaseType = WorkflowGenerator::DB_TYPE_MYSQL;
                $reportExecution->addInfo("Detected Mysql", "will setup Mysql service");
            }
            if ($databaseType === "sqlite") {
                $generator->databaseType = WorkflowGenerator::DB_TYPE_SQLITE;
                $reportExecution->addInfo("Detected Sqlite", "done");
            }
            if ($databaseType === "postgresql") {
                $generator->databaseType = WorkflowGenerator::DB_TYPE_POSTGRESQL;
                $reportExecution->addInfo("Detected PostgreSQL", "will setup pgsql service");
            }
            if ($generator->databaseType !== WorkflowGenerator::DB_TYPE_NONE) {
                $migrationFiles = scandir($guesserFiles->getMigrationsPath());
                if (count($migrationFiles) > 4) {
                    $generator->stepRunMigrations = true;
                    $reportExecution->addInfo("I will execute also migrations", "done");
                }
            }
        }
        if ($guesserFiles->packageExists()) {
            $generator->stepNodejs = true;
            $generator->stepNodejsVersion = "16.x";
            $versionFromNvmrc = $generator->readNvmrc($guesserFiles
            ->getNvmrcPath());
            if ($versionFromNvmrc !== "") {
                $generator->stepNodejsVersion = $versionFromNvmrc;
            }
            $reportExecution->addInfo("NodeJS detected", "version " .  $generator->stepNodejsVersion);
        }
        $appKey = "";
        $generator->stepGenerateKey = false;

        if ($guesserFiles->envDefaultTemplateExists()) {
            $generator->stepCopyEnvTemplateFile = true;
            $generator->stepEnvTemplateFile = $optionEnvWorkflowFile;
            // Generate Key
            $envArray = $generator->readDotEnv($guesserFiles->getEnvDefaultTemplatePath());
            $appKey = Arr::get($envArray, "APP_KEY", "");

            $generator->stepGenerateKey = $appKey === "";
        } else {
            $generator->stepCopyEnvTemplateFile = false;
        }
        $generator->stepFixStoragePermissions = false;
        if ($guesserFiles->artisanExists()) {
            //artisan file so:ENV_TEMPLATE_FILE_DEFAULT.
            // fix storage permissions
            $generator->stepFixStoragePermissions = true;
        }


        $data = $generator->setData();

        $result = $generator->generate($data);
        if ($this->saveFile) {
            try {
                $size = file_put_contents($yamlFile, $result);
                $this->title("Ghygen");
                $this->table(["Report", "status"], $reportExecution->toArrayLabelValue());
                $this->info("File " . $yamlFile);
                $this->info("File created (" . $size . " bytes)");
            } catch (\Exception $e) {
                if (! GuesserFiles::existsGithubWorkflowDirectory($projectdir)) {
                    $this->error(
                        "Workflow directory doesn't exist : " .
                        GuesserFiles::getGithubWorkflowDirectory($projectdir)
                    );
                } else {
                    $this->error($e->getMessage());
                }
            }
        } else {
            $this->line($result);
        }




        return 0;
    }
}
