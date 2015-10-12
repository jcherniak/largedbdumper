#!/usr/bin/env php
<?php
require 'vendor/autoload.php';

use Ifsnop\Mysqldump\Mysqldump;
use GetOptionKit\OptionCollection;
use GetOptionKit\OptionParser;

function print_error_and_exit($msg = '')
{
    global $specs;

    echo $msg . "\n";
    $printer = new GetOptionKit\OptionPrinter\ConsoleOptionPrinter;
    echo $printer->render($specs);
    
    exit(1);
}

set_error_handler(function($severity, $errstr, $errfile, $errline, $errcontext)
{
    if (!(error_reporting() & $severity))
    {
        return;
    }

    throw new \ErrorException($errstr, 0, $severity, $errfile, $errline);
});

$specs = new OptionCollection;
$specs->add('u|username:', 'Username')->isa('String');
$specs->add('d|database:', 'Database')->isa('String');
$specs->add('h|host:', 'Hostname')->isa('String');
$specs->add('m|maxsize:', 'Max table size (MB)')->isa('Number');
$specs->add('o|outputfile:', 'Output filename')->isa('String');
$specs->add('i|includetable+?', 'Force include tables')->isa('String');
$specs->add('s|sshproxy?', 'Server to SSH proxy through')->isa('String');
$specs->add('e|emptytables?', 'Condition for tables to leave empty regardless of size (added to WHERE)')->isa('String');
$specs->add('p|password?', 'Password (only use in scripts, otherwise it is saved in .bash_history)')->isa('String');
$specs->add('c|adddropdb', 'Add DROP DATABASE FOO; CREATE DATABASE FOO;');

try
{
    $parser = new OptionParser($specs);
    $options = $parser->parse($argv)->toArray();
}
catch (\Exception $e)
{
    print_error_and_exit($e->getMessage());
}

foreach (['username', 'database', 'host'] as $required)
{
    if (!isset($options[$required]))
    {
        print_error_and_exit("Missing required parameter {$required}.");
    }
}

$username = $options['username'];
$database = $options['database'];
$host = $options['host'];
$max_size = isset($options['maxsize']) ? $options['maxsize'] : 512;
$output_file = isset($options['outputfile']) ? $options['outputfile'] : $database . '.' . date('Ymd') . '.sql.gz';
$force_includes = isset($options['includetable']) ? $options['includetable'] : array();
$sshproxy = isset($options['sshproxy']) ? $options['sshproxy'] : null;
$always_empty_condition = isset($options['emptytables']) ? $options['emptytables'] : null;
$password = isset($options['password']) ? $options['password'] : null;
$adddropdb = isset($options['adddropdb']);

if (empty($password))
{
    echo "Password for {$username}: ";
    //$password = exec('read -s PW; echo $PW'); //fgets(STDIN);
    $password = fgets(STDIN);
    echo "\n";
}

echo "Dumping database {$database} on {$host} with a max table size of {$max_size} MB.\n";

if (count($force_includes) > 0)
{
   echo "\tIncluding the following tables, regardless of size: " . implode($force_includes, ', ') . "\n";
}

if (!empty($sshproxy))
{
   shell_exec("ssh -f -L 3307:localhost:3306 -N {$sshproxy} sleep 60 >> ssh-proxy.log");
   sleep(2);
   $host = "localhost;port=3307";
}

$db = new PDO("mysql:host={$host};dbname={$database}", $username, $password);

$full_tables = [];
$empty_tables = [];

$always_empty_tables = [];
if (!empty($always_empty_condition))
{
    $sql = <<<SQL
SELECT table_name
FROM information_schema.TABLES
WHERE table_schema = :schema AND ({$always_empty_condition})
SQL;
    $cmd = $db->prepare($sql);
    $cmd->execute([':schema' => $database]);

    $cmd->setFetchMode(PDO::FETCH_ASSOC);

    $total_size = 0;
    foreach ($cmd->fetchAll() as $row)
    {
        $empty_tables[] = $row['table_name'];
    }
}

if (empty($always_empty_condition))
{
    $always_empty_condition = '1=0';
}

$cmd = $db->prepare("SELECT table_name, round(((data_length + index_length) / 1024 / 1024), 2) AS size
    FROM information_schema.TABLES
    WHERE table_schema = :schema AND NOT ({$always_empty_condition})
");
$cmd->execute([':schema' => $database]);

$cmd->setFetchMode(PDO::FETCH_ASSOC);
$total_size = 0;
foreach ($cmd->fetchAll() as $row)
{
    $table = $row['table_name'];

    if ($row['size'] > $max_size && !in_array($table, $force_includes))
    {
        $empty_tables[] = $table;
    }
    else
    {
        $full_tables[] = $table;
        $total_size += $row['size'];
    }
}

echo "\tTotal uncompressed dump size is roughly {$total_size} MB.\n";

$tmpfile = sys_get_temp_dir() . '/' . 'largedbdump.' . md5(time());
echo "\tTemp file prefix is {$tmpfile}.\n";

$cmd = $db->prepare("SHOW GRANTS FOR CURRENT_USER");
$cmd->execute();
$cmd->setFetchMode(PDO::FETCH_NUM);

$has_super = false;
foreach ($cmd->fetchAll() as $row)
{
    if (stripos($row[0], 'super') !== FALSE)
    {
        $has_super = true;
    }
}

try {
    $settings = [
        'include-tables' => $full_tables,
        'add-drop-table' => true,
        'single-transaction' => $has_super,
    ];
   
    $fulldumpfile = $emptydumpfile = null; 
    if (count($full_tables) > 0)
    {
        $fulldumpfile = $tmpfile . '-full.sql';
        $fulldump = new Mysqldump($database, $username, $password, $host, 'mysql', $settings);
        $fulldump->start($fulldumpfile);
    }

    if (count($empty_tables) > 0)
    {
        $settings['include-tables'] = $empty_tables;
        $settings['no-data'] = true;

        $emptydumpfile = $tmpfile . '-empty.sql';
        $emptydump = new Mysqldump($database, $username, $password, $host, 'mysql', $settings);
        $emptydump->start($emptydumpfile);
    }

    $writefunc = 'fwrite';
    $openfunc = 'fopen';
    $closefunc = 'fclose';
    if (stripos($output_file, '.gz') !== FALSE)
    {
        $writefunc = 'gzwrite';
        $openfunc = 'gzopen';
        $closefunc = 'gzclose';
    }

    $outfp = $openfunc($output_file, 'w');
    if (!$outfp)
    {
        throw new \Exception("Error opening output file '{$output_file}' with gzopen");
    }

    if ($adddropdb)
    {
        $dropdbstring = <<<DROPDB
/*!40000 DROP DATABASE IF EXISTS `{$database}`*/;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$database}`;
USE `{$database}`;

DROPDB;

        $writefunc($outfp, $dropdbstring);
    }

    foreach ([$fulldumpfile, $emptydumpfile] as $sourcefile)
    {
        if (empty($sourcefile))
        {
            continue;
        }

        $fp = fopen($sourcefile, 'r');
        if (!$fp)
        {
            throw new \Exception("Error opening full dump file '{$sourcefile}' with fopen");
        }

        while ($data = fread($fp, 1024 * 1024))
        {
            if (!$writefunc($outfp, $data))
            {
                throw new \Exception("Error writing data");
            }
        }

        fclose($fp);
        unlink($sourcefile);
    }

    $closefunc($outfp);

    echo "Success!  Output written to {$output_file}.\n";
    exit(0);
} catch (\Exception $e) {
    echo 'mysqldump-php error: ' . get_class($e) . ' - ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    echo "\n\n\n"; 
    exit(1);
}
