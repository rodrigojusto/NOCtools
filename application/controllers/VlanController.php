<?php

/*
    Copyright (c) 2012, Open Source Solutions Limited, Dublin, Ireland
    All rights reserved.

    This file is part of the NOCtools package.

    Contact: Barry O'Donovan - barry (at) opensolutions (dot) ie
    http://www.opensolutions.ie/

    NOCtools is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    NOCtools is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with NOCtools.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * VlanController
 *
 * @see https://github.com/opensolutions/NOCtools/wiki/VLAN
 * @author Barry O'Donovan <barry@opensolutions.ie>
 */

class VlanController extends NOCtools_Controller_Action
{

    /**
     * The default action - show the home page
     */
    public function indexAction()
    {
    }

    /**
     * A tool that will compare VLANs available (and their respective names) across selected
     * devices allowing you to ensure consistency as well as perform simple security audits.
     *
     * @see https://github.com/opensolutions/NOCtools/wiki/VLAN-Comparison
     */
    public function compareAction()
    {
        if( $this->getRequest()->isPost() )
        {
            // get details for source
            $devices_vlans = array();
            $this->view->source = $source = $this->_getParam( 'source' );
            try
            {
                $host = new \OSS_SNMP\SNMP( $source, $this->_options['community'] );
                $devices_vlans[ $source ] = $host->useCisco_VTP()->vlanNames();
                unset( $host );
            }
            catch( \OSS_SNMP\Exception $e )
            {
                $this->addMessage( "Could not get VLANs from " . $source, OSS_Message::ERROR );
                return;
            }

            $this->view->odevices = $odevices = $this->_getParam( 'odevices', false );

            if( is_array( $odevices ) && count( $odevices ) )
            {
                foreach( $odevices as $dev )
                {
                    if( $dev == $source )
                        continue;

                    try
                    {
                        $host = new \OSS_SNMP\SNMP( $dev, $this->_options['community'] );
                        $devices_vlans[ $dev ] = $host->useCisco_VTP()->vlanNames();
                        unset( $host );
                    }
                    catch( \OSS_SNMP\Exception $e )
                    {
                        $this->addMessage( "Could not get VLANs from " . $dev, OSS_Message::WARNING );
                        if( isset( $devices_vlans[ $dev ] ) ) unset( $devices_vlans[ $dev ] );
                    }
                }
            }

            $allVlans     = array();
            $nameMismatch = array();

            foreach( $devices_vlans as $device => $vlans )
            {
                foreach( $vlans as $vid => $vname )
                {
                    if( !isset( $allVlans[ $vid ] ) )
                        $allVlans[ $vid ] = $vname;
                    else
                    {
                        if( $device != $source && isset( $devices_vlans[ $source ][ $vid ] ) && $devices_vlans[ $source ][ $vid ] != $vname )
                        {
                            if( !isset( $nameMismatch[ $device ] ) )
                                $nameMismatch[ $device ] = array();

                            $nameMismatch[ $device ][$vid] = true;
                        }
                    }
                }
            }

            $this->view->allVlans      = $allVlans;
            $this->view->devices_vlans = $devices_vlans;
            $this->view->nameMismatch  = $nameMismatch;
        }

    }


    /**
     * A tool that will search all devices for a given VLAN number to indicate if it is
     * configured there or not (as well as highlighting name mismatches).
     *
     * @see https://github.com/opensolutions/NOCtools/wiki/VLAN-Search
     */
    public function searchAction()
    {
        if( $this->getRequest()->isPost() )
        {
            // do we have a valid VLAN id?
            $vlan = (int)$this->getParam( 'vlan', 0 );

            if( !$vlan || !is_numeric( $vlan ) || $vlan < 1 || $vlan > 4095 )
            {
                $this->addMessage( "Invalid or no VLAN number / tag specified", OSS_Message::ERROR );
            }
            else
            {
                $this->view->vlan = $vlan;
                $device_vlan = [];
                $firstname = false;   // the name of the vlan as found on the first device
                                      // used for comparison to subsequent devices

                foreach( $this->_devices->devices as $d )
                {
                    try
                    {
                        $host = new \OSS_SNMP\SNMP( $d, $this->_options['community'] );
                        $vlans = $host->useCisco_VTP()->vlanNames();
                        unset( $host );
                    }
                    catch( \OSS_SNMP\Exception $e )
                    {
                        $device_vlan[ $d ] = '_NOCtools_ERR:01';
                        continue;
                    }

                    if( !isset( $vlans[ $vlan ] ) )
                    {
                        $device_vlan[ $d ] = false;
                        continue;
                    }

                    $device_vlan[ $d ] = $vlans[ $vlan ];

                    if( $firstname === false )
                        $firstname = $vlans[ $vlan ];

                    unset( $vlans );
                }

                $this->view->firstname   = $firstname;
                $this->view->device_vlan = $device_vlan;
            }
        }
    }


    /**
     * AJAX function to provide a JSON list of VLANs configured on a particular device.
     *
     * For example, used to dynamically populate dropdowns in @see VlanController::compareAction()
     *
     * @param string $host The SNMP addressable hostname of the device to query for VLAN IDs and names
     * @return JSON Encoded array of [vlanId] => 'vlanName' entries.
     */
    public function ajaxGetForHostAction()
    {
        $host = $this->_getParam( 'host', null );

        if( $host )
        {
            try
            {
                $device = new \OSS_SNMP\SNMP( $host, $this->_options['community'] );
                $vlans = $device->useCisco_VTP()->vlanNames();
                unset( $device );
            }
            catch( \OSS_SNMP\Exception $e )
            {
                return;
            }

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json')
                ->setBody( Zend_Json::encode( $vlans ) )
                ->sendResponse();
            exit( 0 );
        }
    }

}
