<?php

/*
 * Copyright (c) 2014-2019 Christophe Painchaud <shellescape _AT_ gmail.com>                      and Sven Waschkut <pan-c _AT_ waschkut.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/


print "\n***********************************************\n";
print   "*********** SERVICE-EDIT UTILITY **************\n\n";


set_include_path( dirname(__FILE__).'/../'. PATH_SEPARATOR . get_include_path() );
require_once("lib/pan_php_framework.php");
require_once("common/actions.php");


function display_usage_and_exit($shortMessage = false)
{
    global $argv;
    print PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml out=outputfile.xml location=all|shared|sub ".
        "actions=action1:arg1 ['filter=(type is.group) or (name contains datacenter-)']\n";
    print "php ".basename(__FILE__)." listactions   : list supported actions\n";
    print "php ".basename(__FILE__)." listfilters   : list supported filter\n";
    print "php ".basename(__FILE__)." help          : more help messages\n";
    print PH::boldText("\nExamples:\n");
    print " - php ".basename(__FILE__)." type=panorama in=api://192.169.50.10 location=DMZ-Firewall-Group actions=displayReferences 'filter=(name eq Mail-Host1)'\n";
    print " - php ".basename(__FILE__)." type=panos in=config.xml out=output.xml location=any actions=delete\n";

    if( !$shortMessage )
    {
        print PH::boldText("\nListing available arguments\n\n");

        global $supportedArguments;

        ksort($supportedArguments);
        foreach( $supportedArguments as &$arg )
        {
            print " - ".PH::boldText($arg['niceName']);
            if( isset( $arg['argDesc']))
                print '='.$arg['argDesc'];
            //."=";
            if( isset($arg['shortHelp']))
                print "\n     ".$arg['shortHelp'];
            print "\n\n";
        }

        print "\n\n";
    }

    exit(1);
}

function display_error_usage_exit($msg)
{
    fwrite(STDERR, PH::boldText("\n**ERROR** ").$msg."\n\n");
    display_usage_and_exit(true);
}



print "\n";

$configType = null;
$configInput = null;
$configOutput = null;
$doActions = null;
$dryRun = false;
$objectsLocation = 'shared';
$objectsFilter = null;
$errorMessage = '';
$debugAPI = false;



$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file or api. ie: in=config.xml  or in=api://192.168.1.1 or in=api://0018CAEC3@panorama.company.com', 'argDesc' => '[filename]|[api://IP]|[api://serial@IP]');
$supportedArguments['out'] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments['location'] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS. ie: location=any or location=vsys2,vsys1', 'argDesc' => '=sub1[,sub2]');
$supportedArguments['listactions'] = Array('niceName' => 'ListActions', 'shortHelp' => 'lists available Actions');
$supportedArguments['listfilters'] = Array('niceName' => 'ListFilters', 'shortHelp' => 'lists available Filters');
$supportedArguments['stats'] = Array('niceName' => 'Stats', 'shortHelp' => 'display stats after changes');
$supportedArguments['actions'] = Array('niceName' => 'Actions', 'shortHelp' => 'action to apply on each rule matched by Filter. ie: actions=from-Add:net-Inside,netDMZ', 'argDesc' => 'action:arg1[,arg2]' );
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['filter'] = Array('niceName' => 'Filter', 'shortHelp' => "filters objects based on a query. ie: 'filter=((from has external) or (source has privateNet1) and (to has external))'", 'argDesc' => '(field operator [value])');
$supportedArguments['loadplugin'] = Array('niceName' => 'loadPlugin', 'shortHelp' => 'a PHP file which contains a plugin to expand capabilities of this script');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');


PH::processCliArgs();


if( isset(PH::$args['loadplugin']) )
{
    $pluginFile = PH::$args['loadplugin'];
    print " * loadPlugin was used. Now loading file: '{$pluginFile}'...";
    require_once $pluginFile;
    ServiceCallContext::prepareSupportedActions();
    print "OK!\n";
}

if( isset(PH::$args['help']) )
{
    $pos = array_search('help', $argv);

    if( $pos === false )
        display_usage_and_exit(false);

    $keys = array_keys($argv);

    if( $pos == end($keys) )
        display_usage_and_exit(false);

    $action = $argv[(array_search($pos, $keys) +1)];

    if( !isset(ServiceCallContext::$supportedActions[strtolower($action)]) )
        derr("request help for action '{$action}' but it does not exist");

    $action = & ServiceCallContext::$supportedActions[strtolower($action)];

    $args = Array();
    if( isset($action['args']) )
    {
        foreach( $action['args'] as $argName => &$argDetails )
        {
            if( $argDetails['default'] == '*nodefault*' )
                $args[] = "{$argName}";
            else
                $args[] = "[{$argName}]";
        }
    }

    $args = PH::list_to_string($args);
    print "*** help for Action ".PH::boldText($action['name']).":".$args."\n";

    if( isset($action['help']) )
        print $action['help'];

    if( !isset($args) || !isset($action['args']) )
    {
        print "\n\n**No arguments required**";
    }
    else
    {
        print "\nListing arguments:\n\n";
        foreach( $action['args'] as $argName => &$argDetails )
        {
            print "-- ".PH::boldText($argName)." :";
            if( $argDetails['default'] != "*nodefault" )
                print " OPTIONAL";
            print " type={$argDetails['type']}";
            if( isset($argDetails['choices']) )
            {
                print "     choices: ".PH::list_to_string($argDetails['choices']);
            }
            print "\n";
            if( isset($argDetails['help']) )
                print " ".str_replace("\n", "\n ",$argDetails['help']);
            else
                print "  *no help avaiable*";
            print "\n\n";
        }
    }


    print "\n\n";

    exit(0);
}

foreach ( PH::$args as $index => &$arg )
{
    if( !isset($supportedArguments[$index]) )
    {
        if( strpos($index,'subquery') === 0 )
        {
            $nestedQueries[$index] = &$arg;
            continue;
        }
        //var_dump($supportedArguments);
        display_error_usage_exit("unsupported argument provided: '$index'");
    }
}


if( isset(PH::$args['listactions']) )
{
    ksort(ServiceCallContext::$supportedActions);

    print "Listing of supported actions:\n\n";

    print str_pad('', 100, '-')."\n";
    print str_pad('Action name', 28, ' ', STR_PAD_BOTH)."|".str_pad("Argument:Type",24, ' ', STR_PAD_BOTH)." |".
        str_pad("Def. Values",12, ' ', STR_PAD_BOTH)."|   Choices\n";
    print str_pad('', 100, '-')."\n";

    foreach(ServiceCallContext::$supportedActions as &$action )
    {

        $output = "* ".$action['name'];

        $output = str_pad($output, 28).'|';

        if( isset($action['args']) )
        {
            $first = true;
            $count=1;
            foreach($action['args'] as $argName => &$arg)
            {
                if( !$first )
                    $output .= "\n".str_pad('',28).'|';

                $output .= " ".str_pad("#$count $argName:{$arg['type']}", 24)."| ".str_pad("{$arg['default']}",12)."| ";
                if( isset($arg['choices']) )
                {
                    $output .= PH::list_to_string($arg['choices']);
                }

                $count++;
                $first = false;
            }
        }


        print $output."\n";

        print str_pad('', 100, '=')."\n";

        //print "\n";
    }

    exit(0);
}

if( isset(PH::$args['listfilters']) )
{
    ksort(RQuery::$defaultFilters['service']);

    print "Listing of supported filters:\n\n";

    foreach(RQuery::$defaultFilters['service'] as $index => &$filter )
    {
        print "* ".$index."\n";
        ksort( $filter['operators'] );

        foreach( $filter['operators'] as $oindex => &$operator)
        {
            //if( $operator['arg'] )
            $output = "    - $oindex";

            print $output."\n";
        }
        print "\n";
    }

    exit(0);
}



if( ! isset(PH::$args['in']) )
    display_error_usage_exit('"in" is missing from arguments');
$configInput = PH::$args['in'];
if( !is_string($configInput) || strlen($configInput) < 1 )
    display_error_usage_exit('"in" argument is not a valid string');



if( ! isset(PH::$args['actions']) )
    display_error_usage_exit('"actions" is missing from arguments');
$doActions = PH::$args['actions'];
if( !is_string($doActions) || strlen($doActions) < 1 )
    display_error_usage_exit('"actions" argument is not a valid string');


if( isset(PH::$args['dryrun'])  )
{
    $dryRun = PH::$args['dryrun'];
    if( $dryRun === 'yes' ) $dryRun = true;
    if( $dryRun !== true || $dryRun !== false )
        display_error_usage_exit('"dryrun" argument has an invalid value');
}

if( isset(PH::$args['debugapi'])  )
{
    $debugAPI = true;
}


//
// Rule filter provided in CLI ?
//
if( isset(PH::$args['filter'])  )
{
    $objectsFilter = PH::$args['filter'];
    if( !is_string($objectsFilter) || strlen($objectsFilter) < 1 )
        display_error_usage_exit('"filter" argument is not a valid string');
}



//
// What kind of config input do we have.
//     File or API ?
//
// <editor-fold desc="  ****  input method validation and PANOS vs Panorama auto-detect  ****" defaultstate="collapsed" >
$configInput = PH::processIOMethod($configInput, true);
$xmlDoc = null;

if( $configInput['status'] == 'fail' )
{
    fwrite(STDERR, "\n\n**ERROR** " . $configInput['msg'] . "\n\n");exit(1);
}

if( $configInput['type'] == 'file' )
{
    if(isset(PH::$args['out']) )
    {
        $configOutput = PH::$args['out'];
        if (!is_string($configOutput) || strlen($configOutput) < 1)
            display_error_usage_exit('"out" argument is not a valid string');
    }
    else
        display_error_usage_exit('"out" is missing from arguments');

    if( !file_exists($configInput['filename']) )
        derr("file '{$configInput['filename']}' not found");

    $xmlDoc = new DOMDocument();
    if( ! $xmlDoc->load($configInput['filename'], XML_PARSE_BIG_LINES) )
        derr("error while reading xml config file");

}
elseif ( $configInput['type'] == 'api'  )
{
    if($debugAPI)
        $configInput['connector']->setShowApiCalls(true);
    print " - Downloading config from API... ";
    $xmlDoc = $configInput['connector']->getCandidateConfig();
    print "OK!\n";
}
else
    derr('not supported yet');

//
// Determine if PANOS or Panorama
//
$xpathResult = DH::findXPath('/config/devices/entry/vsys', $xmlDoc);
if( $xpathResult === FALSE )
    derr('XPath error happened');
if( $xpathResult->length <1 )
    $configType = 'panorama';
else
    $configType = 'panos';
unset($xpathResult);


if( $configType == 'panos' )
    $pan = new PANConf();
else
    $pan = new PanoramaConf();

print " - Detected platform type is '{$configType}'\n";

if( $configInput['type'] == 'api' )
    $pan->connector = $configInput['connector'];
// </editor-fold>



//
// Location provided in CLI ?
//
if( isset(PH::$args['location'])  )
{
    $objectsLocation = PH::$args['location'];
    if( !is_string($objectsLocation) || strlen($objectsLocation) < 1 )
        display_error_usage_exit('"location" argument is not a valid string');
}
else
{
    if( $configType == 'panos' )
    {
        print " - No 'location' provided so using default ='vsys1'\n";
        $objectsLocation = 'vsys1';
    }
    else
    {
        print " - No 'location' provided so using default ='shared'\n";
        $objectsLocation = 'shared';
    }
}


//
// Extracting actions
//
$explodedActions = explode('/', $doActions);
/** @var ServiceCallContext[] $doActions */
$doActions = Array();
foreach( $explodedActions as &$exAction )
{
    $explodedAction = explode(':', $exAction);
    if( count($explodedAction) > 2 )
        display_error_usage_exit('"actions" argument has illegal syntax: '.PH::$args['actions']);

    $actionName = strtolower($explodedAction[0]);

    if( !isset(ServiceCallContext::$supportedActions[$actionName]) )
    {
        display_error_usage_exit('unsupported Action: "'.$actionName.'"');
    }

    if( count($explodedAction) == 1 )
        $explodedAction[1] = '';

    $context = new ServiceCallContext(ServiceCallContext::$supportedActions[$actionName], $explodedAction[1]);
    $context->baseObject = $pan;
    if( $configInput['type'] == 'api' )
    {
        $context->isAPI = true;
        $context->connector = $pan->connector;
    }

    $doActions[] = $context;
}
//
// ---------


//
// create a RQuery if a filter was provided
//
/**
 * @var RQuery $objectFilterRQuery
 */
$objectFilterRQuery = null;
if( $objectsFilter !== null )
{
    $objectFilterRQuery = new RQuery('service');
    $res = $objectFilterRQuery->parseFromString($objectsFilter, $errorMessage);
    if( $res === false )
    {
        fwrite(STDERR, "\n\n**ERROR** Rule filter parser: " . $errorMessage . "\n\n");
        exit(1);
    }

    print " - filter after sanitization : ".$objectFilterRQuery->sanitizedString()."\n";
}
// --------------------


//
// load the config
//
print " - Loading configuration through PAN-PHP-FRAMEWORK library... ";
$loadStartMem = memory_get_usage(true);
$loadStartTime = microtime(true);
$pan->load_from_domxml($xmlDoc);
$loadEndTime = microtime(true);
$loadEndMem = memory_get_usage(true);
$loadElapsedTime = number_format( ($loadEndTime - $loadStartTime), 2, '.', '');
$loadUsedMem = convert($loadEndMem - $loadStartMem);
print "OK! ($loadElapsedTime seconds, $loadUsedMem memory)\n";
// --------------------



//
// Location Filter Processing
//

// <editor-fold desc=" ****  Location Filter Processing  ****" defaultstate="collapsed" >
/**
 * @var RuleStore[] $ruleStoresToProcess
 */
$objectsLocation = explode(',', $objectsLocation);

foreach( $objectsLocation as &$location )
{
    if( strtolower($location) == 'shared' )
        $location = 'shared';
    else if( strtolower($location) == 'any' )
        $location = 'any';
    else if( strtolower($location) == 'all' )
        $location = 'any';
}
unset($location);

$objectsLocation = array_unique($objectsLocation);
$objectsToProcess = Array();

foreach( $objectsLocation as $location )
{
    $locationFound = false;

    if( $configType == 'panos')
    {
        if( $location == 'shared' || $location == 'any'  )
        {
            $objectsToProcess[] = Array('store' => $pan->serviceStore, 'objects' => $pan->serviceStore->all(true));
            $locationFound = true;
        }
        foreach ($pan->getVirtualSystems() as $sub)
        {
            if( ($location == 'any' || $location == 'all' || $location == $sub->name() && !isset($ruleStoresToProcess[$sub->name()]) ))
            {
                $objectsToProcess[] = Array('store' => $sub->serviceStore, 'objects' => $sub->serviceStore->all(true));
                $locationFound = true;
            }
        }
    }
    else
    {
        if( $location == 'shared' || $location == 'any' )
        {

            $objectsToProcess[] = Array('store' => $pan->serviceStore, 'objects' => $pan->serviceStore->all(true));
            $locationFound = true;
        }

        foreach( $pan->getDeviceGroups() as $sub )
        {
            if( ($location == 'any' || $location == 'all' || $location == $sub->name()) && !isset($ruleStoresToProcess[$sub->name().'%pre']) )
            {
                $objectsToProcess[] = Array('store' => $sub->serviceStore, 'objects' => $sub->serviceStore->all(true) );
                $locationFound = true;
            }
        }
    }

    if( !$locationFound )
    {
        print "ERROR: location '$location' was not found. Here is a list of available ones:\n";
        print " - shared\n";
        if( $configType == 'panos' )
        {
            foreach( $pan->getVirtualSystems() as $sub )
            {
                print " - ".$sub->name()."\n";
            }
        }
        else
        {
            foreach( $pan->getDeviceGroups() as $sub )
            {
                print " - ".$sub->name()."\n";
            }
        }
        print "\n\n";
        exit(1);
    }
}
// </editor-fold>


foreach( $doActions as $doAction )
{
    if( $doAction->hasGlobalInitAction() )
    {
        $doAction->subSystem = $sub;
        $doAction->executeGlobalInitAction();
    }
}

//
// It's time to process Rules !!!!
//

// <editor-fold desc=" *****  Object Processing  *****" defaultstate="collapsed" >

$totalObjectsProcessed = 0;

foreach( $objectsToProcess as &$objectsRecord )
{
    $subObjectsProcessed = 0;

    /** @var ServiceStore $store */
    $store = $objectsRecord['store'];
    $objects = &$objectsRecord['objects'];
    foreach( $doActions as $doAction )
    {
        $doAction->subSystem = $store->owner;
    }

    print "\n* processing store '".PH::boldText($store->toString())." that holds ".PH::boldText(count($objects))." objects\n";


    foreach($objects as $object )
    {
        if( $objectFilterRQuery !== null )
        {
            $queryResult = $objectFilterRQuery->matchSingleObject($object);
            if( !$queryResult )
                continue;
        }

        $totalObjectsProcessed++;
        $subObjectsProcessed++;

        //mwarning($object->name());

        foreach( $doActions as $doAction )
        {
            $doAction->padding = '     ';
            $doAction->executeAction($object);

            print "\n";
        }
    }

    print "* objects processed in DG/Vsys '{$store->owner->name()}' : $subObjectsProcessed\n\n";
}
// </editor-fold>

$first  = true;
foreach( $doActions as $doAction )
{
    if( $doAction->hasGlobalFinishAction() )
    {
        $first = false;
        $doAction->executeGlobalFinishAction();
    }
}

print "\n **** PROCESSING OF $totalObjectsProcessed OBJECTS DONE **** \n\n";

if( isset(PH::$args['stats']) )
{
    $pan->display_statistics();
    print "\n";
    $processedLocations = Array();
    foreach( $objectsToProcess as &$record )
    {
        if( get_class($record['store']->owner) != 'PanoramaConf' && get_class($record['store']->owner) != 'PANConf' )
        {
            /** @var DeviceGroup|VirtualSystem $sub */
            $sub = $record['store']->owner;
            if( isset($processedLocations[$sub->name()]) )
                continue;

            $processedLocations[$sub->name()] = true;
            $sub->display_statistics();
            echo "\n";
        }
    }
}


// save our work !!!
if( $configOutput !== null )
{
    if( $configOutput != '/dev/null' )
    {
        $pan->save_to_file($configOutput);
    }
}


print "\n\n*********** END OF SERVICE-EDIT UTILITY **********\n";
print     "**************************************************\n";
print "\n\n";




