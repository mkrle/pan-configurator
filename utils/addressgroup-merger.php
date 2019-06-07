<?php
/*
 * Copyright (c) 2014-2017 Christophe Painchaud <shellescape _AT_ gmail.com>
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

echo "\n***********************************************\n";
echo   "*********** ".basename(__FILE__)." UTILITY **********\n\n";

set_include_path( dirname(__FILE__).'/../'. PATH_SEPARATOR . get_include_path() );
require_once("lib/panconfigurator.php");
require_once(dirname(__FILE__).'/common/misc.php');


$supportedArguments = Array();
$supportedArguments[] = Array('niceName' => 'in', 'shortHelp' => 'input file ie: in=config.xml', 'argDesc' => '[filename]');
$supportedArguments[] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments[] = Array(
    'niceName' => 'DupAlgorithm',
    'shortHelp' =>
        "Specifies how to detect duplicates:\n".
        "  - SameMembers: groups holding same members replaced by the one picked first (default)\n".
        "  - SameIP4Mapping: groups resolving the same IP4 coverage will be replaced by the one picked first\n".
        "  - WhereUsed: groups used exactly in the same location will be merged into 1 single groups with all members together\n",
    'argDesc'=> 'SameMembers|SameIP4Mapping|WhereUsed');
$supportedArguments[] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS', 'argDesc' => '=sys1|shared|dg1');
$supportedArguments[] = Array('niceName' => 'mergeCountLimit', 'shortHelp' => 'stop operations after X objects have been merged', 'argDesc'=> '100');
$supportedArguments[] = Array('niceName' => 'pickFilter', 'shortHelp' => 'specify a filter a pick which object will be kept while others will be replaced by this one', 'argDesc' => '(name regex /^g/)');
$supportedArguments[] = Array('niceName' => 'excludeFilter', 'shortHelp' => 'specify a filter to exclude objects from merging process entirely', 'argDesc' => '(name regex /^g/)');
$supportedArguments[] = Array('niceName' => 'allowMergingWithUpperLevel', 'shortHelp' => 'when this argument is specified, it instructs the script to also look for duplicates in upper level');
$supportedArguments[] = Array('niceName' => 'help', 'shortHelp' => 'this message');

$usageMsg = PH::boldText('USAGE: ')."php ".basename(__FILE__)." in=inputfile.xml [out=outputfile.xml] location=shared [DupAlgorithm=SameMembers] ['pickFilter=(name regex /^H-/)'] ...";

prepareSupportedArgumentsArray($supportedArguments);

PH::processCliArgs();

$nestedQueries = Array();

// check that only supported arguments were provided
foreach ( PH::$args as $index => &$arg )
{
    if( !isset($supportedArguments[$index]) )
    {
        if( strpos($index,'subquery') === 0 )
        {
            $nestedQueries[$index] = &$arg;
            continue;
        }
        display_error_usage_exit("unsupported argument provided: '$index'");
    }
}

if( isset(PH::$args['help']) )
{
    display_usage_and_exit();
}


if( !isset(PH::$args['in']) )
    display_error_usage_exit(' "in=" argument is missing');

if( !isset(PH::$args['location']) )
    display_error_usage_exit(' "location=" argument is missing');

$location = PH::$args['location'];

if( isset(PH::$args['mergecountlimit']) )
    $mergeCountLimit = PH::$args['mergecountlimit'];
else
    $mergeCountLimit = false;


//
// What kind of config input do we have.
//     File or API ?
//
// <editor-fold desc="  ****  input method validation and PANOS vs Panorama auto-detect  ****" defaultstate="collapsed" >
$configInput = PH::processIOMethod(PH::$args['in'], true);
$xmlDoc = null;

if( $configInput['status'] == 'fail' )
{
    fwrite(STDERR, "\n\n**ERROR** " . $configInput['msg'] . "\n\n");exit(1);
}

if( $configInput['type'] == 'file' )
{
    $apiMode = false;
    if( !file_exists($configInput['filename']) )
        derr("file '{$configInput['filename']}' not found");

    $xmlDoc = new DOMDocument();
    echo " - Reading XML file from disk... ";
    if( ! $xmlDoc->load($configInput['filename'], XML_PARSE_BIG_LINES) )
        derr("error while reading xml config file");
    echo "OK!\n";

}
elseif ( $configInput['type'] == 'api'  )
{
    $apiMode = true;
    echo " - Downloading config from API... ";
    $xmlDoc = $configInput['connector']->getCandidateConfig();
    echo "OK!\n";
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
    $panc = new PANConf();
else
    $panc = new PanoramaConf();

echo " - Detected platform type is '{$configType}'\n";

if( $configInput['type'] == 'api' )
    $panc->connector = $configInput['connector'];

//
// load the config
//
echo " - Loading configuration through PAN-Configurator library... ";
$loadStartMem = memory_get_usage(true);
$loadStartTime = microtime(true);
$panc->load_from_domxml($xmlDoc);
$loadEndTime = microtime(true);
$loadEndMem = memory_get_usage(true);
$loadElapsedTime = number_format( ($loadEndTime - $loadStartTime), 2, '.', '');
$loadUsedMem = convert($loadEndMem - $loadStartMem);
echo "OK! ($loadElapsedTime seconds, $loadUsedMem memory)\n";
// --------------------

// </editor-fold>


if( !$apiMode )
{
    if( !isset(PH::$args['out']) )
        display_error_usage_exit(' "out=" argument is missing');

    $outputfile = PH::$args['out'];

    // destroy destination file if it exists
    if( file_exists($outputfile) && is_file($outputfile) )
        unlink($outputfile);
}

$location_array = array();
if( $location == 'any' || $location == 'all' )
{
    if( $panc->isPanorama() )
        $alldevicegroup = $panc->deviceGroups;
    else
        $alldevicegroup = $panc->virtualSystems;


    foreach( $alldevicegroup as $key => $tmp_location )
    {
        $location = $tmp_location->name();
        $findLocation = $panc->findSubSystemByName($location);
        if( $findLocation === null )
            derr("cannot find DeviceGroup/VSYS named '{$location}', check case or syntax");

        $store = $findLocation->addressStore;
        $parentStore = $findLocation->owner->addressStore;

        $location_array[$key]['findLocation'] = $findLocation;
        $location_array[$key]['store'] = $store;
        $location_array[$key]['parentStore'] = $parentStore;
        if( $panc->isPanorama() )
        {
            $childDeviceGroups = $findLocation->childDeviceGroups(true);
            $location_array[$key]['childDeviceGroups'] = $childDeviceGroups;
        }
        else
            $location_array[$key]['childDeviceGroups'] = array();

    }
    $location_array[$key+1]['findLocation'] = 'shared';
    $location_array[$key+1]['store'] = $panc->addressStore;
    $location_array[$key+1]['parentStore'] = null;
    $location_array[$key+1]['childDeviceGroups'] = $alldevicegroup;

}
else
{
    if( $location == 'shared' )
    {
        $store = $panc->addressStore;
        $parentStore = null;
        $location_array[0]['findLocation'] = $location;
        $location_array[0]['store'] = $store;
        $location_array[0]['parentStore'] = $parentStore;
    }
    else
    {
        $findLocation = $panc->findSubSystemByName($location);
        if( $findLocation === null )
            derr("cannot find DeviceGroup/VSYS named '{$location}', check case or syntax");

        $store = $findLocation->addressStore;
        $parentStore = $findLocation->owner->addressStore;

        $location_array[0]['findLocation'] = $findLocation;
        $location_array[0]['store'] = $store;
        $location_array[0]['parentStore'] = $parentStore;
    }

    if( $panc->isPanorama() )
    {
        if( $location == 'shared' )
            $childDeviceGroups = $panc->deviceGroups;
        else
            $childDeviceGroups = $findLocation->childDeviceGroups(true);
        $location_array[0]['childDeviceGroups'] = $childDeviceGroups;
    }
    else
        $location_array[0]['childDeviceGroups'] = array();
}


$pickFilter = null;
if( isset(PH::$args['pickfilter']) )
{
    $pickFilter = new RQuery('address');
    $errMsg = '';
    if( $pickFilter->parseFromString(PH::$args['pickfilter'], $errMsg) === FALSE )
        derr("invalid pickFilter was input: ".$errMsg);
    echo " - pickFilter was input: ";
    $pickFilter->display();
    echo "\n";

}
$excludeFilter = null;
if( isset(PH::$args['excludefilter']) )
{
    $excludeFilter = new RQuery('address');
    $errMsg = '';
    if( $excludeFilter->parseFromString(PH::$args['excludefilter'], $errMsg) === FALSE )
        derr("invalid pickFilter was input: ".$errMsg);
    echo " - excludeFilter was input: ";
    $excludeFilter->display();
    echo "\n";
}


$upperLevelSearch = false;
if( isset(PH::$args['allowmergingwithupperlevel']) )
    $upperLevelSearch = true;

if( isset(PH::$args['dupalgorithm']) )
{
    $dupAlg = strtolower(PH::$args['dupalgorithm']);
    if( $dupAlg != 'samemembers' && $dupAlg != 'sameip4mapping' && $dupAlg != 'whereused')
        display_error_usage_exit('unsupported value for dupAlgorithm: '.PH::$args['dupalgorithm']);
}
else
    $dupAlg = 'samemembers';

foreach( $location_array as $tmp_location )
{
    $store = $tmp_location['store'];
    $findLocation = $tmp_location['findLocation'];
    $parentStore = $tmp_location['parentStore'];
    $childDeviceGroups = $tmp_location['childDeviceGroups'];

    echo " - upper level search status : " . boolYesNo($upperLevelSearch) . "\n";
    if( is_string($findLocation) )
        echo " - location 'shared' found\n";
    else
        echo " - location '{$findLocation->name()}' found\n";
    echo " - found {$store->count()} address Objects\n";
    echo " - DupAlgorithm selected: {$dupAlg}\n";
    echo " - computing AddressGroup values database ... ";
    sleep(1);

    /**
     * @param AddressGroup $object
     * @return string
     */
    if( $dupAlg == 'samemembers' )
        $hashGenerator = function ($object)
        {
            /** @var AddressGroup $object */
            $value = '';

            $members = $object->members();
            usort($members, '__CmpObjName');

            foreach( $members as $member )
            {
                $value .= './.' . $member->name();
            }

            //$value = md5($value);

            return $value;
        };
    elseif( $dupAlg == 'sameip4mapping' )
        $hashGenerator = function ($object)
        {
            /** @var AddressGroup $object */
            $value = '';

            $mapping = $object->getFullMapping();

            $value = $mapping['ip4']->dumpToString();

            if( count($mapping['unresolved']) > 0 )
            {
                ksort($mapping['unresolved']);
                $value .= '//unresolved:/';

                foreach( $mapping['unresolved'] as $unresolvedEntry )
                    $value .= $unresolvedEntry->name() . '.%.';
            }
            //$value = md5($value);

            return $value;
        };
    elseif( $dupAlg == 'whereused' )
        $hashGenerator = function ($object)
        {
            if( $object->countReferences() == 0 )
                return null;

            /** @var AddressGroup $object */
            $value = $object->getRefHashComp() . '//dynamic:' . boolYesNo($object->isDynamic());

            return $value;
        };
    else
        derr("unsupported dupAlgorithm");

//
// Building a hash table of all address objects with same value
//
    if( $upperLevelSearch )
        $objectsToSearchThrough = $store->nestedPointOfView();
    else
        $objectsToSearchThrough = $store->addressGroups();

    $hashMap = Array();
    $upperHashMap = Array();
    foreach( $objectsToSearchThrough as $object )
    {
        if( !$object->isGroup() || $object->isDynamic() )
            continue;

        if( $excludeFilter !== null && $excludeFilter->matchSingleObject(Array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
            continue;

        $skipThisOne = FALSE;

        // Object with descendants in lower device groups should be excluded
        if( $panc->isPanorama() )
        {
            foreach( $childDeviceGroups as $dg )
            {
                if( $dg->addressStore->find($object->name(), null, FALSE) !== null )
                {
                    $skipThisOne = TRUE;
                    break;
                }
            }
            if( $skipThisOne )
                continue;
        }

        $value = $hashGenerator($object);
        if( $value === null )
            continue;

        if( $object->owner === $store )
        {
            $hashMap[$value][] = $object;
            if( $parentStore !== null )
            {
                $findAncestor = $parentStore->find($object->name(), null, TRUE);
                if( $findAncestor !== null )
                    $object->ancestor = $findAncestor;
            }
        }
        else
            $upperHashMap[$value][] = $object;
    }

//
// Hashes with single entries have no duplicate, let's remove them
//
    $countConcernedObjects = 0;
    foreach( $hashMap as $index => &$hash )
    {
        if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
        {
            //echo "\nancestor not found for ".reset($hash)->name()."\n";
            unset($hashMap[$index]);
        }
        else
            $countConcernedObjects += count($hash);
    }
    unset($hash);
    echo "OK!\n";

    echo " - found " . count($hashMap) . " duplicate values totalling {$countConcernedObjects} groups which are duplicate\n";

    echo "\n\nNow going after each duplicates for a replacement\n";

    $countRemoved = 0;
    foreach( $hashMap as $index => &$hash )
    {
        echo "\n";
        echo " - value '{$index}'\n";

        $pickedObject = null;

        if( $pickFilter !== null )
        {
            if( isset($upperHashMap[$index]) )
            {
                foreach( $upperHashMap[$index] as $object )
                {
                    if( $pickFilter->matchSingleObject(Array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                    {
                        $pickedObject = $object;
                        break;
                    }
                }
                if( $pickedObject === null )
                    $pickedObject = reset($upperHashMap[$index]);

                echo "   * using object from upper level : '{$pickedObject->name()}'\n";
            }
            else
            {
                foreach( $hash as $object )
                {
                    if( $pickFilter->matchSingleObject(Array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                    {
                        $pickedObject = $object;
                        break;
                    }
                }
                if( $pickedObject === null )
                    $pickedObject = reset($hash);

                echo "   * keeping object '{$pickedObject->name()}'\n";
            }
        }
        else
        {
            if( isset($upperHashMap[$index]) )
            {
                $pickedObject = reset($upperHashMap[$index]);
                echo "   * using object from upper level : '{$pickedObject->name()}'\n";
            }
            else
            {
                $pickedObject = reset($hash);
                echo "   * keeping object '{$pickedObject->name()}'\n";
            }
        }

        // Merging loop finally!
        foreach( $hash as $object )
        {
            /** @var AddressGroup $object */
            if( isset($object->ancestor) )
            {
                $ancestor = $object->ancestor;
                /** @var AddressGroup $ancestor */
                if( $upperLevelSearch && $ancestor->isGroup() && !$ancestor->isDynamic() && $dupAlg != 'whereused' )
                {
                    if( $hashGenerator($object) == $hashGenerator($ancestor) )
                    {
                        echo "    - group '{$object->name()}' merged with its ancestor, deleting this one... ";
                        $object->replaceMeGlobally($ancestor);
                        if( $apiMode )
                            $object->owner->API_remove($object, TRUE);
                        else
                            $object->owner->remove($object, TRUE);

                        echo "OK!\n";

                        if( $pickedObject === $object )
                            $pickedObject = $ancestor;

                        $countRemoved++;
                        if( $mergeCountLimit !== FALSE && $countRemoved >= $mergeCountLimit )
                        {
                            echo "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$mergeCountLimit})\n";
                            break 2;
                        }
                        continue;
                    }
                }
                echo "    - group '{$object->name()}' cannot be merged because it has an ancestor\n";
                continue;
            }

            if( $object === $pickedObject )
                continue;

            if( $dupAlg == 'whereused' )
            {
                echo "    - merging '{$object->name()}' members into '{$pickedObject->name()}': \n";
                foreach( $object->members() as $member )
                {
                    echo "     - adding member '{$member->name()}'... ";
                    if( $apiMode )
                        $pickedObject->API_addMember($member);
                    else
                        $pickedObject->addMember($member);
                    echo " OK!\n";
                }
                echo "    - now removing '{$object->name()} from where it's used\n";
                if( $apiMode )
                {
                    $object->API_removeWhereIamUsed(TRUE, 6);
                    echo "    - deleting '{$object->name()}'... ";
                    $object->owner->API_remove($object);
                    echo "OK!\n";
                }
                else
                {
                    $object->removeWhereIamUsed(TRUE, 6);
                    echo "    - deleting '{$object->name()}'... ";
                    $object->owner->remove($object);
                    echo "OK!\n";
                }
            }
            else
            {
                echo "    - replacing '{$object->_PANC_shortName()}' ...\n";
                $object->__replaceWhereIamUsed($apiMode, $pickedObject, TRUE, 5);

                echo "    - deleting '{$object->_PANC_shortName()}'\n";
                if( $apiMode )
                {
                    //true flag needed for nested groups in a specific constellation
                    $object->owner->API_remove($object, TRUE);
                }
                else
                {
                    $object->owner->remove($object, TRUE);
                }
            }

            $countRemoved++;

            if( $mergeCountLimit !== FALSE && $countRemoved >= $mergeCountLimit )
            {
                echo "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$mergeCountLimit})\n";
                break 2;
            }
        }
    }

    echo "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countAddressGroups()}' (removed {$countRemoved} groups)\n\n";

    echo "\n\n***********************************************\n\n";

    echo "\n\n";
}

if( !$apiMode )
    $panc->save_to_file($outputfile);

echo "\n************* END OF SCRIPT ".basename(__FILE__)." ************\n\n";



