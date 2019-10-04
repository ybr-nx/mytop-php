<?php

namespace Mytop;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use PDOException;
use PDO;

class MytopCommand extends Command {

    protected $user = null;
    protected $password = null;
    protected $host = 'localhost';
    protected $port = 3306;

    protected $connection = null;

    protected $input;
    protected $output;

    protected function configure()
    {
        $this
            ->setName('mytop')
            ->setDescription('Start mytop and monitor processlist')
            ->setDefinition(
                new InputDefinition(array(
                    new InputOption('host', '', InputOption::VALUE_REQUIRED),
                    new InputOption('port', '', InputOption::VALUE_REQUIRED),
                    new InputOption('user', '', InputOption::VALUE_REQUIRED),
                    new InputOption('password', '', InputOption::VALUE_REQUIRED),
                ))
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->input = $input;
        $this->output = $output;

        $opts = [
            'user',
            'password',
            'host',
            'port',
        ];

        $configs = [
            '/etc/.my.cnf',
            '/etc/mysql/.my.cnf',
            '/usr/etc/.my.cnf',
            '/root/.my.cnf',
            $_SERVER['HOME'] . '/.my.cnf',
        ];

        foreach ($configs as $config) {
            if (file_exists($config) && is_readable($config)) {
                $parsedConfig = parse_ini_file($config, 'client');
                if (isset($parsedConfig['client'])) {
                    $parsedConfig = $parsedConfig['client'];
                    foreach($opts as $opt) {
                        if (array_key_exists($opt, $parsedConfig)) {
                            $this->{$opt} = $parsedConfig[$opt];
                        }
                    }
                }
            }
        }

        $configs = [
            $_SERVER['HOME'] . '/.mytop',
        ];
        foreach ($configs as $config) {
            if (file_exists($config) && is_readable($config)) {
                $parsedConfig = parse_ini_file($config);

                foreach($opts as $opt) {
                    if (array_key_exists($opt, $parsedConfig)) {
                        $this->{$opt} = $parsedConfig[$opt];
                    }
                }
            }
        }
        
        foreach ($opts as $opt) {
            if ($input->getOption($opt)) {
                $this->{$opt} = $input->getOption($opt);
            }
        }

        $error = false;
        foreach ($opts as $opt) {
            if (!$this->{$opt}) {
                $output->writeln('<error>' . $opt . ' is unset, please use .my.cnf or .mytop or command line variable --' . $opt . '</>');
                $error = true;
            }
        }
        if ($error) {
            return;
        }

        $dsn = 'mysql:host=' . $this->host . ($this->port ? ':' . $this->port : '') . ';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES         => false
        ];

        if (!$this->connection) {
            try {
                $this->connection = new PDO($dsn, $this->user, $this->password, $options);
            } 
            catch (PDOException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</>');
                $output->writeln('<error>' . (int)$e->getCode() . '</>');
                return;
            }
        }

        // Some things we only take once. For example version will never change
        $stmt = $this->connection->query('SHOW VARIABLES LIKE "%version%"');

        $versionInfo = array_column($stmt->fetchAll(), 'Value', 'Variable_name');

        $waiter = fopen('php://stdin', 'r');
        $this->waiter = $waiter;

        while (true) {

            $uptime = $stmt = current($this->connection->query('SHOW GLOBAL STATUS LIKE "Uptime"')->fetchAll())['Value'];

            $stmt = $this->connection->query('SHOW FULL PROCESSLIST');

            $processList = $stmt->fetchAll();

            //clear screen
            $output->write(sprintf("\033\143"));

            //banner
            $output->writeln('');
            $output->writeln('Running ' . $versionInfo['version'] . ' on ' . $versionInfo['version_compile_os'] . ' ' . $versionInfo['version_compile_machine']);
            $output->writeln('InnoDB version: ' . $versionInfo['innodb_version']);
            $output->writeln('Uptime: ' . $uptime . 's');


            //show processes
            if (count($processList)) {

                $processlistToShow = $processList;
                foreach ($processlistToShow as &$thisProcess) {
                    if (strlen($thisProcess['Info']) > 50) {
                        $thisProcess['Info'] = substr($thisProcess['Info'], 0, 50) . '...';
                    }
                }
                unset($thisProcess);

                $table = new Table($output);
                $table->setHeaders(array_keys($processList[0]));
                $table->setRows($processlistToShow);
                $table->render();
            }
            else {
                $output->info('No running processes');
            }


            $output->writeln('');
            $output->writeln('Shortcuts');
            $output->writeln('k - kill process');
            $output->writeln('e - explain');
            $output->writeln('m - queries per second');            
            $output->writeln('q - quit');     

            $sttyMode = shell_exec('stty -g');
            shell_exec('stty -icanon -echo');
            

            // we wait for commands here
            // k - kill
            // enter - reload
            stream_set_blocking($waiter, false);
            stream_set_timeout($waiter, 2, 1);

            //5 seconds until new processess output
            $command = false;
            for ($i = 0; $i < 10; $i++) {
                while($char = fgetc($waiter)) {
                    if (!empty($char) && in_array($char, ['k', 'q', 'e', 'm'], true)) {
                        $command = $char;
                        break;
                    }
                }
                usleep(50000);
            }
            //restore default stdin
            stream_set_blocking($waiter, true);
            // Reset stty so it behaves normally again
            shell_exec(sprintf('stty %s', $sttyMode));

            if ($command && $command == 'k') {
                $processIds = array_column($processList, 'Id');
                $helper = $this->getHelper('question');
                $question = new Question('Process to kill: ');
                $question->setAutocompleterValues($processIds);
                $processToKill = $helper->ask($input, $output, $question);
                if (!in_array($processToKill, $processIds)) {
                    $output->writeln('Process not found');
                    sleep(2);
                }
                else {
                    $this->connection->exec('KILL ' . $processToKill);
                }
            }
            elseif ($command && $command == 'm') {
                $this->queriesPerSecond();
            }
            elseif ($command && $command == 'e') {
                $processIds = array_column($processList, 'Id');
                $helper = $this->getHelper('question');
                $question = new Question('Process to explain: ');
                $question->setAutocompleterValues($processIds);
                $processToExplain = $helper->ask($input, $output, $question);
                $processQueries = array_column($processList, 'Info', 'Id');

                if (!array_key_exists($processToExplain, $processQueries)) {
                    $output->writeln('Process not found');
                    sleep(2);
                }
                else {
                    try {
                        $dbs = array_column($processList, 'db', 'Id');
                        if (is_string($dbs[$processToExplain]) && strlen($dbs[$processToExplain])) {
                            $this->connection->exec('USE ' . $dbs[$processToExplain]);
                        }

                        //TODO if "Command" == "Query", then we can explain
                        $explainData = $this->connection->query('EXPLAIN ' . $processQueries[$processToExplain])->fetchAll()[0];
                        $explainTableData = [];
                        foreach ($explainData as $key => $value) {
                            $explainTableData[] = [
                                'key'   => $key,
                                'value' => $value,
                            ];
                        }

                        $output->write(sprintf("\033\143"));
                        $table = new Table($output);
                        $table->setHeaders(['Key', 'Value']);
                        $table->setRows($explainTableData);
                        $output->writeln('');
                        $output->writeln('EXPLAIN ' . $processQueries[$processToExplain]);
                        $output->writeln('');
                        $table->render();
                    }
                    catch(PDOException $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</>');
                    }
                    
                    $helper = $this->getHelper('question');
                    $question = new Question('Press [enter] to continue.');
                    $continue = $helper->ask($input, $output, $question);
                }
            }
            elseif ($command && $command == 'q') {
                return;
            }
        }

        fclose($waiter);        
    }

    protected function queriesPerSecond()
    {
        $output = $this->output;
        $input = $this->input;
        $waiter = $this->waiter;

        $output->write(sprintf("\033\143"));
        $output->writeln('Queries Per Second [hit q to exit this mode]');

        $stmt = $this->connection->query('SHOW GLOBAL STATUS like "Queries"');

        $lastOne = $stmt->fetchAll()[0]['Value'];

        sleep(1);

        $sttyMode = shell_exec('stty -g');
        shell_exec('stty -icanon -echo');
        stream_set_blocking($waiter, false);

        while (true) {
            $stmt = $this->connection->query('SHOW GLOBAL STATUS like "Queries"');
            $thisOne = $stmt->fetchAll()[0]['Value'];

            $output->writeln(date('H:i:s ' . ($thisOne - $lastOne)));

            sleep(1);
            $char = fgetc($waiter);
            if (!empty($char) && in_array($char, ['q'], true)) {
                //restore default stdin
                stream_set_blocking($waiter, true);
                // Reset tty so it behaves normally again
                shell_exec(sprintf('stty %s', $sttyMode));
                return;
            }            

            $lastOne = $thisOne;
        }
    }
}