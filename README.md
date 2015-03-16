# largedbdumper

This tool helps you dump large MySQL databases by including data for tables 
below a certain size and only the table structure for larger tables.

## Options
    -u, --username=<String>
        Username

    -d, --database=<String>
        Database

    -h, --host=<String>
        Hostname

    -m, --maxsize=<Number>
        Max table size (MB)

    -o, --outputfile=<File>
        Output filename

## Example
php dump-large-db.php -u USERNAME -d DATABASE -h HOSTNAME -m MAX_SIZE_IN_MB -o OUTPUT_FILE_NAME
