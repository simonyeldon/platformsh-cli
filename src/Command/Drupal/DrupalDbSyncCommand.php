<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DrupalDbSyncCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:db-sync')
         ->setAliases(array('db-sync'))
         ->setDescription('Synchronize local database with designated remote')
         ->addOption('app', NULL, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specify application(s) to import the database for.')
         ->addOption('no-sanitize', 'S', InputOption::VALUE_NONE, 'Do not perform database sanitization.');
    $this->addDirectoryArgument();
    $this->addEnvironmentOption();
    $this->addExample('Synchronize database of a Drupal project from daily backup', '-e environmentID /path/to/project')
         ->addExample('Synchronize database of a Drupal project from daily backup; do not sanitize it', '--no-sanitize -e environmentID /path/to/project');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $apps = $input->getOption('app');
    foreach (LocalApplication::getApplications($this->getProjectRoot(), self::$config) as $app) {
      if ($apps && !in_array($app->getId(), $apps)) {
        continue;
      }
      if ($app->getConfig()['build']['flavor'] == 'drupal') {
        $this->_execute($input, $app);
      }
    }
  }

  protected function _execute(InputInterface $input, LocalApplication $app) {
    $project = $this->getSelectedProject();
    $envId = $input->getOption('environment');
    $slugifiedTitle = $this->getSlug($project, $app);
    $backupPath = self::$config->get('local.deploy.db_backup_local_cache') . "/" . date('Y-m-d') . '_' . $slugifiedTitle . '.sql';

    $this->stdErr->writeln("Importing live database backup for <info>" . $project->id . '-' . $app->getId() . "</info>");

    // Get a fresh SQL dump if necessary.
    if (!file_exists($backupPath)) {
      $this->stdErr->writeln("Downloading backup to: <info>$backupPath</info>");

      // SCP and GUNZIP compressed database backup.
      $sh = new ShellHelper();
      $sh->execute([
        'scp',
        $project->id . "-" . $envId . "--" . $app->getId() . "@ssh." . $project->getProperty('region') . ".platform.sh:~/private/" . $project->id . ".sql.gz",
        "$backupPath.gz"
      ]);
      $sh->execute(['gunzip', "$backupPath.gz"]);

      // If the the above didn't work, use sql-dump command.
      if (!file_exists($backupPath)) {
        $this->runOtherCommand('db:dump', [
          '--project' => $project->id,
          '--environment' => $envId,
          '--app' => $app->getId(),
          '--file' => $backupPath
        ]);
      }
    }
    else {
      $this->stdErr->writeln("Retrieving backup from the cache: <info>$backupPath</info>");
    }

    // If dump is present and sound.
    if (file_exists($backupPath) && filesize($backupPath) > 0) {
      $dbName = self::$config->get('local.stack.mysql_db_prefix') . $slugifiedTitle;
      // Use PHP MySQL APIs for these simple queries.
      $queries = array(
        "DROP DATABASE IF EXISTS $dbName",
        "CREATE DATABASE IF NOT EXISTS $dbName",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON $dbName.* TO '" . self::$config->get('local.stack.mysql_user') . "'@'" . self::$config->get('local.stack.mysql_host') . "' IDENTIFIED BY '" . self::$config->get('local.stack.mysql_password') . "';"
      );
      if ($connection = mysqli_connect(self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_root_user'), self::$config->get('local.stack.mysql_root_password'))) {
        foreach ($queries as $q) {
          mysqli_query($connection, $q);
        }
        mysqli_close($connection);
      }
      else {
        $this->stdErr->writeln('<error>Could not connect to MySQL. Try again later.</error>');
        return 1;
      }

      // Use mysql CLI for importing the SQL dump, as it's much more efficient.
      $cmd = sprintf("cat %s | mysql -h%s -u%s -p%s --database %s", $backupPath, self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_root_user'), self::$config->get('local.stack.mysql_root_password'), $dbName);
      $p = new Process($cmd);
      $p->setTimeout(self::$config->get('local.deploy.external_process_timeout'));
      try {
        $p->mustRun();
      }
      catch (ProcessFailedException $e) {
        echo $e->getMessage();
        return 1;
      }

      // Sanitise, if requested.
      if (!$input->getOption('no-sanitize')) {
        $this->runOtherCommand('drupal:db-sanitize', [
          "directory" => $this->getProjectRoot(),
          "--app" => $app->getId()
        ]);
      }
      return 0;
    }
    else {
      $this->stdErr->writeln('<error>Backup could not be downloaded. Try again later.</error>');
      return 1;
    }
  }
}

