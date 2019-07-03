<?php

echo "\n*************************************************\n";
echo   "**************** FILTER TESTERS *****************\n\n";

require_once '../lib/panos_php_framework.php';

PH::processCliArgs();

if( ini_get('safe_mode') ){
    derr("SAFE MODE IS ACTIVE");
}



function runCommand($bin, &$stream, $force = true, $command = '')
{
    $stream = '';

    $bin .= $force ? " 2>&1" : '';

    $descriptorSpec = array
    (
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $pipes = Array();

    $process = proc_open($bin, $descriptorSpec, $pipes);

    if( $process !== FALSE )
    {
        fwrite($pipes[0], $command);
        fclose($pipes[0]);

        $stream = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stream += stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    }
    else
        return -1;

}

$totalFilterCount = 0;
$totalFilterWithCiCount = 0;
$missing_filters = array();

foreach( RQuery::$defaultFilters as $type => &$filtersByField )
{
    foreach($filtersByField as $fieldName => &$filtersByOperator )
    {
        foreach( $filtersByOperator['operators'] as $operator => &$filter )
        {
            $totalFilterCount++;

            if( !isset($filter['ci']) )
            {
                $missing_filters[$type][] = $fieldName . " ".$operator;
                continue;
            }


            $totalFilterWithCiCount++;

            if( $operator == '>,<,=,!' )
                $operator = '<';

            echo "\n\n\n *** Processing filter: {$type} / ({$fieldName} {$operator})\n";

            $ci = &$filter['ci'];

            $filterString = str_replace('%PROP%',  "{$fieldName} {$operator}", $ci['fString']);


            if( $type == 'rule' )
                $util = '../utils/rules-edit.php';
            elseif( $type == 'address' )
                $util = '../utils/address-edit.php';
            elseif( $type == 'service' )
                $util = '../utils/service-edit.php';
            elseif( $type == 'tag' )
                $util = '../utils/tag-edit.php';
            elseif( $type == 'app' )
            {
                echo "******* SKIPPED for now *******\n";
                continue;
            }
            else
                derr('unsupported');

            $location = 'any';
            $output = '/dev/null';
            $ruletype = 'any';


            $cli = "php $util in={$ci['input']} out={$output} location={$location} actions=display 'filter={$filterString}'";

            if( $type == 'rule' )
                $cli .= " ruletype={$ruletype}";

            $cli .= ' 2>&1';

            echo " * Executing CLI: {$cli}\n";

            $output = Array();
            $retValue = 0;

            exec($cli, $output, $retValue);

            foreach($output as $line)
            {
                echo '   ##  '; echo $line; echo "\n";
            }

            if( $retValue != 0 )
                derr("CLI exit with error code '{$retValue}'");

            echo "\n";

        }
    }
}

echo "\n*****  *****\n";
echo " - Processed {$totalFilterCount} filters\n";
echo " - Found {$totalFilterWithCiCount} that are CI enabled\n";

print_r($missing_filters);

echo "\n";
echo "\n*********** FINISHED TESTING FILTERS ************\n";
echo   "*************************************************\n\n";




