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

class TunnelInterface
{
    use InterfaceType;
    use XmlConvertible;
    use PathableName;
    use ReferencableObject;

    protected $_ipv4Addresses = Array();

    /** @var string */
    public $type = 'tunnel';

    function __construct($name, $owner)
    {
        $this->name = $name;
        $this->owner = $owner;
    }


    public function isTunnelType()
    {
        return true;
    }

    public function load_from_domxml( DOMElement $xml )
    {
        $this->xmlroot = $xml;

        $this->name = DH::findAttribute('name', $xml);
        if( $this->name === FALSE )
            derr("tunnel name name not found\n");

        $ipNode = DH::findFirstElement('ip', $xml);
        if( $ipNode !== false )
        {
            foreach( $ipNode->childNodes as $l3ipNode )
            {
                if( $l3ipNode->nodeType != XML_ELEMENT_NODE )
                    continue;

                $this->_ipv4Addresses[] = $l3ipNode->getAttribute('name');
            }
        }
    }

    /**
     * @return string
     */
    public function type()
    {
        return $this->type;
    }

    public function getIPv4Addresses()
    {
        return $this->_ipv4Addresses;
    }

    /**
     * return true if change was successful false if not (duplicate rulename?)
     * @return bool
     * @param string $name new name for the rule
     */
    public function setName($name)
    {
        if( $this->name == $name )
            return true;

        $this->name = $name;

        $this->xmlroot->setAttribute('name', $name);

        return true;

    }

    /**
     * @return string
     */
    public function &getXPath()
    {
        $str = $this->owner->getTunnelIfStoreXPath()."/entry[@name='".$this->name."']";

        return $str;
    }

    static public $templatexml = '<entry name="**temporarynamechangeme**"><ip/></entry>';
}