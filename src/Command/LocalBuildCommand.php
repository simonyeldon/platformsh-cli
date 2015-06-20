<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildCommand extends PlatformCommand
{

    protected $defaultDrushConcurrency = 4;

    protected function configure()
    {
        $this
          ->setName('local:build')
          ->setAliases(array('build'))
          ->addArgument('app', InputArgument::IS_ARRAY, 'Specify application(s) to build')
          ->setDescription('Build the current project locally')
          ->addOption(
            'abslinks',
            'a',
            InputOption::VALUE_NONE,
            'Use absolute links'
          )
          ->addOption(
            'source',
            null,
            InputOption::VALUE_OPTIONAL,
            'The source directory. Default: ' . LocalProject::REPOSITORY_DIR
          )
          ->addOption(
            'destination',
            null,
            InputOption::VALUE_OPTIONAL,
            'The destination, to which the web root of each app will be symlinked. Default: ' . LocalProject::WEB_ROOT
          )
          ->addOption(
            'copy',
            null,
            InputOption::VALUE_NONE,
            'Copy to a build directory, instead of symlinking from the source'
          )
          ->addOption(
            'no-clean',
            null,
            InputOption::VALUE_NONE,
            'Do not remove old builds'
          )
          ->addOption(
            'no-archive',
            null,
            InputOption::VALUE_NONE,
            'Do not create or use a build archive'
          )
          ->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'Disable caching'
          )
          ->addOption(
            'no-build-hooks',
            null,
            InputOption::VALUE_NONE,
            'Do not run post-build hooks'
          );
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            $this->addOption(
              'working-copy',
              null,
              InputOption::VALUE_NONE,
              'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version'
            )
            ->addOption(
              'concurrency',
              null,
              InputOption::VALUE_OPTIONAL,
              'Drush: set the number of concurrent projects that will be processed at the same time',
              $this->defaultDrushConcurrency
            );
        }
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $sourceDirOption = $input->getOption('source');
        if (!$projectRoot && !$sourceDirOption && $input->isInteractive()) {
            $sourceDirOption = $questionHelper->askInput('Source directory', $input, $this->stdErr, '.');
        }

        if ($sourceDirOption) {
            $sourceDir = realpath($sourceDirOption);
            if (!is_dir($sourceDir)) {
                throw new \InvalidArgumentException('Source directory not found: ' . $sourceDirOption);
            }
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --source or go to a project directory.');
        }
        else {
            $sourceDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        }

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');

        $destination = $input->getOption('destination');

        if (!$projectRoot && !$destination && $input->isInteractive()) {
            $destination = $questionHelper->askInput('Build destination', $input, $this->stdErr, 'www');
        }

        if ($destination) {
            $destination = $fsHelper->makePathAbsolute($destination);

            if (file_exists($destination)) {
                $default = is_link($destination);
                if (!$questionHelper->confirm("The destination exists: <comment>$destination</comment>. Overwrite?", $input, $this->stdErr, $default)) {
                    return 1;
                }
            }
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --destination or go to a project directory.');
        }
        else {
            $destination = $projectRoot . '/' . LocalProject::WEB_ROOT;
        }

        // Ensure no conflicts between source and destination.
        if (strpos($sourceDir, $destination) === 0) {
            throw new \InvalidArgumentException("The destination '$destination' conflicts with the source '$sourceDir'");
        }

        $settings = array();

        // Find out the real environment ID, if possible.
        if ($projectRoot && $this->isLoggedIn()) {
            $project = $this->getCurrentProject();
            if ($project) {
                $environment = $this->getCurrentEnvironment($project);
                if ($environment) {
                    $settings['environmentId'] = $environment['id'];
                }
            }
        }

        // Otherwise, use the Git branch name.
        if (!isset($settings['environmentId']) && is_dir($sourceDir . '/.git')) {
            $gitHelper = $this->getHelper('git');
            $settings['environmentId'] = $gitHelper->getCurrentBranch($sourceDir, true);
        }

        $settings['projectRoot'] = $projectRoot;

        $settings['verbosity'] = $output->getVerbosity();

        $settings['drushConcurrency'] = $input->hasOption('concurrency') ? $input->getOption('concurrency') : $this->defaultDrushConcurrency;

        // Some simple settings flags.
        $settingsMap = array(
          'absoluteLinks' => 'abslinks',
          'copy' => 'copy',
          'drushWorkingCopy' => 'working-copy',
          'noArchive' => 'no-archive',
          'noCache' => 'no-cache',
          'noClean' => 'no-clean',
          'noBuildHooks' => 'no-build-hooks',
        );
        foreach ($settingsMap as $setting => $option) {
            $settings[$setting] = $input->hasOption($option) && $input->getOption($option);
        }

        $apps = $input->getArgument('app');

        $builder = new LocalBuild($settings, $this->stdErr);
        $success = $builder->build($sourceDir, $destination, $apps);

        return $success ? 0 : 1;
    }

}