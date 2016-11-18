<?php

namespace Platformsh\Cli\Command\Drupal;

use Cocur\Slugify\Slugify;
use Drush\Make\Parser\ParserIni;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;

class DrupalDeployCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:deploy')
         ->setAliases(array('deploy'))
         ->setDescription('Deploy a Drupal Site locally')
         ->addOption('db-sync', 'd', InputOption::VALUE_NONE, "Sync project's database with the daily live backup.")
         ->addOption('core-branch', 'c', InputOption::VALUE_REQUIRED, "The core profile's branch to use during deployment")
         ->addOption('no-archive', 'A', InputOption::VALUE_NONE, 'Do not create or use a build archive. Run \'platform help build\' for more info.')
         ->addOption('no-deploy-hooks', 'D', InputOption::VALUE_NONE, 'Do not run deployment hooks (drush commands).')
         ->addOption('no-git-pull', 'G', InputOption::VALUE_NONE, 'Do not fetch updates for Git repositories. ')
         ->addOption('no-reindex', 'I', InputOption::VALUE_NONE, 'Do not reindex the content after creating indices for the first time during deployment.')
         ->addOption('no-sanitize', 'S', InputOption::VALUE_NONE, 'Do not perform database sanitization.')
         ->addOption('no-unclean-features', 'U', InputOption::VALUE_NONE, 'Shows a list of unclean features (if any) at the end of the deployment.');
    $this->addExample('Deploy Drupal project', '-p myproject123')
         ->addExample('Deploy Drupal project refreshing the database from the backup', '-d -p myproject123')
         ->addExample('Deploy Drupal project rereshing the database from the backup but skipping sanitization', '-d -S -p myproject123');
    $this->addProjectOption();
  }


  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();

    $siteJustFetched = FALSE;
    $profileJustFetched = FALSE;

    $this->stdErr->writeln("<info>[*]</info> Deployment started for <info>" . $project->getProperty('title') . "</info> ($project->id)");

    // If this directory does not exist, create it.
    if (!is_dir($this->profilesRootDir)) {
      mkdir($this->profilesRootDir);
    }

    // If this directory does not exist, create it.
    if (!is_dir($this->sitesRootDir)) {
      mkdir($this->sitesRootDir);
    }

    // The 'currentProject' array is defined as a protected attribute of the
    // base class ExtendedCommandBase.
    $this->extCurrentProject['root_dir'] = $this->sitesRootDir . '/' . $this->extCurrentProject['internal_site_code'];

    // If the project was never deployed before.
    if (!is_dir($this->extCurrentProject['root_dir'] . '/www') && !is_dir($this->extCurrentProject['root_dir'] . '/_www')) {
      $this->fetchSite($project);
      // DB sync is required the first time the project is deployed.
      $input->setOption('db-sync', InputOption::VALUE_NONE);
      $siteJustFetched = TRUE;
    }

    // Set project root.
    $this->setProjectRoot($this->extCurrentProject['root_dir']);

    // Set more info about the current project.
    $this->extCurrentProject['legacy'] = $this->localProject->getLegacyProjectRoot() !== FALSE;
    $this->extCurrentProject['repository_dir'] = $this->extCurrentProject['legacy'] ?
      $this->extCurrentProject['root_dir'] . '/repository' :
      $this->extCurrentProject['root_dir'];
    $this->extCurrentProject['www_dir'] = $this->extCurrentProject['legacy'] ?
      $this->extCurrentProject['root_dir'] . '/www' :
      $this->extCurrentProject['root_dir'] . '/_www';

    // Check for profile info.
    $profile = $this->getProfileInfo();

    // If the project refers to a profile and this was never fetched before.
    if (is_array($profile) && (!is_dir($this->profilesRootDir . "/" . $profile['name']))) {
      $this->fetchProfile($profile);
      $profileJustFetched = TRUE;
    }

    // Update repositories, if not requested otherwise.
    if (!$input->getOption('no-git-pull')) {
      // If we are not to fetch from a remote core branch.
      if (is_array($profile) && !$profileJustFetched && !$input->getOption('core-branch')) {
        $this->updateRepository($this->profilesRootDir . "/" . $profile['name']);
      }
      else {
        $this->stdErr->writeln('<info>[*]</info> Ignoring local profile repository. Using remote with branch <info>' . $input->getOption('core-branch') . '</info>');
      }
      // Update site repo only if the site has not just been fetched
      // for the first time.
      if (!$siteJustFetched) {
        // GitHub integration can be activated any time, so we must be ready
        // to apply it.
        $this->checkGitHubIntegration();
        // Update the repo.
        $this->updateRepository($this->extCurrentProject['repository_dir']);
      }
    }

    // DB import.
    if ($input->getOption('db-sync')) {
      $this->runOtherCommand('drupal:dbsync', [
        '--no-sanitize' => TRUE,
        'directory' => $this->extCurrentProject['root_dir'],
      ]);
    }

    // Perform platform build.
    $this->build($project, $profile, $input->getOption('no-archive'), $input->getOption('core-branch'));

    // If the profiles uses Elastic Search.
    if (file_exists($this->extCurrentProject['www_dir'] . '/profiles/' . $profile['name'] . '/modules/contrib/search_api_elasticsearch')) {
      // Create Elastic Search indices.
      $_reindex = $this->createElasticSearchIndices($project);
    }

    // DB sanitize.
    if ($input->getOption('db-sync') && !$input->getOption('no-sanitize')) {
      $this->runOtherCommand('drupal:db-sanitize', ['directory' => $this->extCurrentProject['root_dir']]);
    }

    // Run deployment hooks, if not requested otherwise.
    if (!$input->getOption('no-deploy-hooks')) {
      $this->runDeployHooks($project);
    }

    // Mount remote file share.
    $this->runOtherCommand('drupal:mount', ['directory' => $this->extCurrentProject['root_dir']]);

    // Show unclean features, if not requested otherwise.
    if (!$input->getOption('no-unclean-features')) {
      $this->stdErr->writeln("<info>[*]</info> Checking the status of features...");
      $this->runOtherCommand('drupal:unclean-features', ['directory' => $this->extCurrentProject['root_dir']]);
    }

    // Clean up builds.
    $this->cleanBuilds();

    // End of deployment.
    $this->stdErr->writeln("<info>[*]</info> Deployment finished.\n\tGo to <info>http://" . $this->extCurrentProject['internal_site_code'] . self::$config->get('local.deploy.local_domain'). "</info> to view the site.\n\tThe password for all users is <info>password</info>.");
  }

  /**
   * Build project.
   * @param $project
   * @param array $profile
   * @param $noArchive
   * @param $noDeployHooks
   * @param $coreBranch
   */
  private function build(Project $project, $profile, $noArchive, $coreBranch = NULL) {
    $this->stdErr->writeln('<info>[*]</info> Building <info>' . $project->getProperty('title') . '</info> (' . $project->id . ')...');

    // This step is not required for projects that do not
    // use external distro profiles.
    if (is_array($profile)) {
      // Temporarily override the project.make to use the local checkout of the
      // core profile, which must be on on the branch one desires to deploy.
      $originalMakefile = file_get_contents($this->extCurrentProject['repository_dir'] . '/project.make');
      if ($coreBranch) {
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[branch\] =) (.*)/', "$1 $coreBranch", $originalMakefile);
      }
      else {
        $tempMakefile = preg_replace('/projects\[' . $profile['name'] . '\]\[download\]\[branch\] = .*/', "", $originalMakefile);
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[url\] =) (.*)/', "$1 " . $this->profilesRootDir . '/' . $profile['name'], $tempMakefile);
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[type\] =) (.*)/', "$1 copy", $tempMakefile);
      }
      file_put_contents($this->extCurrentProject['repository_dir'] . '/project.make', $tempMakefile);
    }

    // Build.
    $localBuildOptions['--yes'] = TRUE;
    $localBuildOptions['--source'] = $this->extCurrentProject['root_dir'];
    if ($noArchive) {
      $localBuildOptions['--no-archive'] = TRUE;
    }
    $this->runOtherCommand('local:build', $localBuildOptions);

    // Make sure settings.local.php is up to date for the project.
    $slugify = new Slugify();
    $slugifiedProjectTitle = $project->title ? str_replace('-', '_', $slugify->slugify($project->title)) : $project->id;
    $settings_local_php = sprintf(file_get_contents("/vagrant/etc/cli/resources/drupal/settings.local.php"), self::$config->get('local.stack.mysql_db_prefix') . $slugifiedProjectTitle, self::$config->get('local.stack.mysql_user'), self::$config->get('local.stack.mysql_password'), self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_port'));
    // Account for Legacy projects CLI < 3.x
    if (!($sharedPath = $this->localProject->getLegacyProjectRoot())) {
      $sharedPath = $this->getProjectRoot() . '/.platform/local';
    }
    file_put_contents($sharedPath . "/shared/settings.local.php", $settings_local_php);

    // This step is not required for projects that do not
    // use external distro profiles.
    if (is_array($profile)) {
      // Restore original project.make so that the site repo is clean.
      file_put_contents($this->extCurrentProject['repository_dir'] . '/project.make', $originalMakefile);

      // Skip this step if $coreBranch is not NULL.
      if ($coreBranch == NULL) {
        $fs = new Filesystem();

        // Remove the .git directory that we got from the "build" of type "copy".
        $fs->remove($this->extCurrentProject['root_dir'] . '/www/profiles/' . $profile['name'] . '/.git');

        // Obtain symlinks map for profile.
        $linkMap = $this->mapProfileSymlinks($profile);

        // Remove all files represented by $linkMap's keys.
        $fs->remove(array_keys($linkMap));

        // Replace them with required symlinks.
        foreach ($linkMap as $original => $link) {
          $symlink = rtrim($fs->makePathRelative($link, realpath(dirname($original))), '/');
          $fs->symlink($symlink, $original);
        }
      }
    }
  }

  /**
   * Fetch the Drupal profile for the first time.
   * @throws \Exception
   */
  private function fetchProfile($profile) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $this->stdErr->writeln("<info>[*]</info> Checking out <info>" . $profile['name'] . "</info> for the first time...");

    $git->cloneRepo($profile['url'],  $this->profilesRootDir . "/" . $profile['name'], array(
      '--branch',
      isset($profile['branch']) ? $profile['branch'] : self::$config->get('local.deploy.git_default_branch'),
    ));

    if (isset($profile['tag'])) {
      $git->checkOut($profile['tag'],  $this->profilesRootDir . "/" . $profile['name'], TRUE);
    }
  }

  /**
   * Fetches a site for the first time.
   * @param $project
   * @throws \Exception
   */
  private function fetchSite(Project $project) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $this->stdErr->writeln("<info>[*]</info> Fetching <info>" . $project->getProperty('title') . "</info> (" . $project->id . ") for the first time...");
    $this->runOtherCommand('project:get', [
      '--yes' => TRUE,
      '--environment' => self::$config->get('local.deploy.git_default_branch'),
      'id' => $project->id,
      'directory' => $this->extCurrentProject['root_dir'],
    ]);
  }

  /**
   * Check for GitHub integration and act accordingly.
   * @param $project
   */
  private function checkGitHubIntegration() {
    if ($this->gitHubIntegrationAvailable() && !$this->gitHubIntegrationEnabled()) {
      $this->stdErr->writeln("\n<info>Found GitHub integration</info>: the remote <info>origin</info> will now be pointed at Github...");
      $this->stdErr->writeln("The remote <info>platform</info> will continue to point at Platform.sh");
      $this->enableGitHubIntegration();
    }
  }

  /**
   * Update a repository.
   * @param $dir
   *  Directory where the checked out repo lives.
   * @throws \Exception
   */
  private function updateRepository($dir) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $git->setDefaultRepositoryDir($dir);
    $this->stdErr->write(sprintf("<info>[*]</info> Updating <info>%s/%s</info>...", basename(str_ireplace(':', '/', $git->getConfig('remote.origin.url')), '.git'), $git->getCurrentBranch()));
    $git->execute(array('pull'));
    $git->execute(array(
      'pull',
      'origin',
      self::$config->get('local.deploy.git_default_branch'),
    ));
    $this->stdErr->writeln("\t<info>[ok]</info>");
  }

  /**
   * Execute the deployment hooks.
   * @param $project
   */
  private function runDeployHooks(Project $project) {
    $localApplication = new LocalApplication($this->extCurrentProject['repository_dir']);
    $appConfig = $localApplication->getConfig();

    $this->stdErr->writeln("<info>[*]</info> Executing deployment hooks for <info>$project->title</info> ($project->id)...");

    $sh = new ShellHelper();
    foreach (explode("\n", $appConfig['hooks']['deploy']) as $hook) {
      if ($hook != "cd public") {
        if (strlen($hook) > 0) {
          $this->stdErr->writeln("Running <info>$hook</info>");
          if (stripos($hook, 'updb')) {
            $sh->executeSimple($hook, $this->extCurrentProject['www_dir']);
          }
          else {
            $sh->execute(explode(' ', $hook), $this->extCurrentProject['www_dir']);
          }
        }
      }
    }
  }

  /**
   * Clean up old builds for the project and keep only the latest.
   */
  private function cleanBuilds() {
    $this->stdErr->writeln('<info>[*]</info> Deleting old builds...');
    chdir($this->extCurrentProject['root_dir']);
    $this->runOtherCommand('local:clean', [
      '--keep' => 1,
    ]);
  }

  /**
   * Get information on the profile used by the project, if one is specified.
   */
  private function getProfileInfo() {
    $makefile = $this->extCurrentProject['repository_dir'] . '/project.make';
    if (file_exists($makefile)) {
      $ini = new ParserIni();
      $makeData = $ini->parse(file_get_contents($makefile));
      foreach ($makeData['projects'] as $projectName => $projectInfo) {
        if (($projectInfo['type'] == 'profile') && ($projectInfo['download']['type'] == 'git' || ($projectInfo['download']['type'] == 'copy'))) {
          $p = $projectInfo['download'];
          $p['name'] = $projectName;
          unset($p['type']);
          return $p;
        }
      }
    }
    return NULL;
  }

  /**
   * Build symlinks map for a given profile.
   *
   * @param $profile
   * @return array
   */
  private function mapProfileSymlinks($profile) {
    $profileName = $profile['name'];
    $profileDir = $this->profilesRootDir . "/" . $profileName;
    $wwwDir = $this->extCurrentProject['www_dir'];

    // The keys of the $linkMap array are the files to remove.
    // The values are the files to symlink to, in place of the removed files.
    $linkMap = array();

    // Symlink profile files.
    foreach (array('.info', '.profile', '.install', '.make') as $ext) {
      $linkMap[$wwwDir . '/profiles/' . $profileName . '/' . $profileName . $ext] = $profileDir . '/' . $profileName . $ext;
    }

    // This will symlink things like: modules/custom, modules/features, // etc.
    if ($modules = scandir($profileDir . "/modules")) {
      foreach ($modules as $module_dir) {
        if (($module_dir != '.') && ($module_dir != '..') && ($module_dir != 'contrib')) {
          $linkMap[$wwwDir . '/profiles/' . $profileName . '/modules/' . $module_dir] = $profileDir . '/modules/' . $module_dir;
        }
      }
    }

    // This will symlink the themes.
    if ($themes = scandir($profileDir . "/themes")) {
      foreach ($themes as $theme_dir) {
        if (($theme_dir != '.') && ($theme_dir != '..') && ($theme_dir != 'contrib')) {
          $linkMap[$wwwDir . '/profiles/' . $profileName . '/themes/' . $theme_dir] = $profileDir . '/themes/' . $theme_dir;
        }
      }
    }

    // Legacy solas2-like profiles have no "resources"
    // directory, so all is found one level up.
    $resource_dir = file_exists($wwwDir . '/profiles/' . $profileName . '/resources') && is_dir($wwwDir . '/profiles/' . $profileName . '/resources') ?
      '/resources' : '';

    // Symlink various resources.
    $linkMap[$wwwDir . '/profiles/' . $profileName . $resource_dir . '/settings'] = $profileDir . $resource_dir . '/settings';
    $linkMap[$wwwDir . '/profiles/' . $profileName . $resource_dir . '/sureroute-test-object.html'] = $profileDir . $resource_dir . '/sureroute-test-object.html';
    $linkMap[$wwwDir . '/sureroute-test-object.html'] = $profileDir . $resource_dir . '/sureroute-test-object.html';
    $linkMap[$wwwDir . '/profiles/' . $profileName . $resource_dir . '/humans.txt'] = $profileDir . $resource_dir . '/humans.txt';
    $linkMap[$wwwDir . '/humans.txt'] = $profileDir . $resource_dir . '/humans.txt';

    return $linkMap;
  }

  /**
   * Creates ES indices for a project, if not present.
   * @param $project
   * @return bool Whether or not to reindex.
   */
  private function createElasticSearchIndices(Project $project) {
    $slugify = new Slugify();
    $slugifiedTitle = $project->title ? $slugify->slugify($project->title) : $project->id;
    $dbName = self::$config->get('local.stack.mysql_db_prefix') . str_replace('-', '_', $slugifiedTitle);
    $elasticSearchBaseUrl = 'http://' . self::$config->get('local.stack.elasticsearch_host') . ":" . self::$config->get('local.stack.elasticsearch_port') . '/';

    // Use PHP MySQL APIs for these simple queries.
    $query = "SELECT sai.machine_name FROM search_api_index sai INNER JOIN search_api_server sas ON sai.server = sas.machine_name WHERE sas.class = 'search_api_elasticsearch_elastica_service'";
    if ($connection = mysqli_connect(self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_root_user'), self::$config->get('local.stack.mysql_root_password'))) {
      mysqli_select_db($connection, $dbName);
      $r = mysqli_query($connection, $query);
    }
    else {
      $this->stdErr->writeln('<error>Could not connect to MySQL. Try again later.</error>');
      return 1;
    }

    // Guzzle Http Client.
    $c = new Client();
    $_reindex = FALSE;
    while ($row = mysqli_fetch_assoc($r)) {
      $index = "elasticsearch_index_" . $dbName . "_" . $row['machine_name'];
      try {
        $c->head($elasticSearchBaseUrl . $index);
      }
      catch (RequestException $e) {
        if ($e->getCode() == 404) {
          // Create it.
          $this->stdErr->writeln("<info>[*]</info> Creating empty Elastic Search index <info>$index</info>...");
          $c->put($elasticSearchBaseUrl . $index);
          $_reindex = TRUE;
        }
      }
    }

    mysqli_close($connection);

    return $_reindex;
  }

}