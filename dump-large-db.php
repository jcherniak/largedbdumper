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

echo "Password for {$username}: ";
$password = exec('read -s PW; echo $PW'); //fgets(STDIN);
echo "\n";

echo "Dumping database {$database} on {$host} with a max table size of {$max_size} MB.\n";

if (count($force_includes) > 0)
{
   echo "\tIncluding the following tables, regardless of size: " . implode($force_includes, ', ') . "\n";
}

$db = new PDO("mysql:host={$host};dbname={$database}", $username, $password);

$full_tables = [];
$empty_tables = [];

$cmd = $db->prepare('SELECT table_name, round(((data_length + index_length) / 1024 / 1024), 2) AS size
    FROM information_schema.TABLES
    WHERE table_schema = :schema
');
$cmd->execute([':schema' => $database]);

$cmd->setFetchMode(PDO::FETCH_ASSOC);

$total_size = 0;
foreach ($cmd->fetchAll() as $row)
{
    if ($row['size'] > $max_size && !in_array($row['table_name'], $force_includes))
    {
        $empty_tables[] = $row['table_name'];
    }
    else
    {
        $full_tables[] = $row['table_name'];
        $total_size += $row['size'];
    }
}

echo "\tTotal uncompressed dump size is roughly {$total_size} MB.\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'largedbdump');
echo "\tTemp file prefix is {$tmpfile}.\n";

try {
    $settings = [
        'include-tables' => $full_tables,
        'add-drop-table' => true,
    ];
    
    $fulldumpfile = $tmpfile . '-full.sql';
    $fulldump = new Mysqldump($database, $username, $password, $host, 'mysql', $settings);
    $fulldump->start($fulldumpfile);

    $settings['include-tables'] = $empty_tables;
    $settings['no-data'] = true;

    $emptydumpfile = $tmpfile . '-empty.sql';
    $emptydump = new Mysqldump($database, $username, $password, $host, 'mysql', $settings);
    $emptydump->start($emptydumpfile);

    $outfp = gzopen($output_file, 'w');
    if (!$outfp)
    {
        throw new \Exception("Error opening output file '{$output_file}' with gzopen");
    }

    $fp = fopen($fulldumpfile, 'r');
    if (!$fp)
    {
        throw new \Exception("Error opening full dump file '{$fulldumpfile}' with fopen");
    }

    while ($data = fread($fp, 1024 * 1024))
    {
        if (!gzwrite($outfp, $data))
        {
            throw new \Exception("Error writing data");
        }
    }

    fclose($fp);

    $fp = fopen($emptydumpfile, 'r');
    if (!$fp)
    {
        throw new \Exception("Error opening full dump file '{$fulldumpfile}' with fopen");
    }

    while ($data = fread($fp, 1024 * 1024))
    {
        if (!gzwrite($outfp, $data))
        {
            throw new \Exception("Error writing data");
        }
    }

    fclose($fp);

    gzclose($outfp);

    unlink($fulldumpfile);
    unlink($emptydumpfile);

    echo "Success!  Output written to {$output_file}.\n";
    exit(0);
} catch (\Exception $e) {
    echo 'mysqldump-php error: ' . $e->getMessage();
    exit(1);
}
