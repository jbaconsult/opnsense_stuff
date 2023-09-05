<?php

/*
 * Copyright (C) 2014-2021 Deciso B.V.
 * Copyright (C) 2004-2009 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>
 * Copyright (C) 2012 Bobby Earl www.bobbyearl.com
 * Copyright (C) 2021 Johannes Bayer-Albert <dermozart@gmail.com>
 *
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * This script is based on parts on the dhcp_status.php and the script by Bobby Earl   
 * https://github.com/bobbyearl/pfSense-DHCP-leases-widget/blob/master/DHCP_leases.widget.php
 *
 */

require_once("guiconfig.inc");
require_once("config.inc");
require_once("interfaces.inc");
require_once("plugins.inc.d/dhcpd.inc");

function remove_duplicate($array, $field)
{
    foreach ($array as $sub) {
        $cmp[] = $sub[$field];
    }
    $unique = array_unique(array_reverse($cmp,true));
    foreach ($unique as $k => $rien) {
        $new[] = $array[$k];
    }
    return $new;
}

$interfaces = legacy_config_get_interfaces(array('virtual' => false));
$leasesfile = '/var/dhcpd/var/db/dhcpd.leases';

    $awk = "/usr/bin/awk";
    /* this pattern sticks comments into a single array item */
    $cleanpattern = "'{ gsub(\"#.*\", \"\");} { gsub(\";\", \"\"); print;}'";
    /* We then split the leases file by } */
    $splitpattern = "'BEGIN { RS=\"}\";} {for (i=1; i<=NF; i++) printf \"%s \", \$i; printf \"}\\n\";}'";

    /* stuff the leases file in a proper format into an array by line */
    exec("/bin/cat {$leasesfile} | {$awk} {$cleanpattern} | {$awk} {$splitpattern}", $leases_content);
    $leases_count = count($leases_content);
    exec("/usr/sbin/arp -an", $rawdata);
    $arpdata_ip = array();
    $arpdata_mac = array();
    foreach ($rawdata as $line) {
        $elements = explode(' ',$line);
        if ($elements[3] != "(incomplete)") {
            $arpent = array();
            $arpdata_ip[] = trim(str_replace(array('(',')'),'',$elements[1]));
            $arpdata_mac[] = strtolower(trim($elements[3]));
        }
    }
    unset($rawdata);
    $pools = array();
    $leases = array();
    $i = 0;
    $l = 0;
    $p = 0;

    // Put everything together again
    foreach($leases_content as $lease) {
        /* split the line by space */
        $data = explode(" ", $lease);
        /* walk the fields */
        $f = 0;
        $fcount = count($data);
        /* with less then 20 fields there is nothing useful */
        if ($fcount < 20) {
            $i++;
            continue;
        }
        while($f < $fcount) {
            switch($data[$f]) {
                case "failover":
                    $pools[$p]['name'] = trim($data[$f+2], '"');
                    $pools[$p]['name'] = "{$pools[$p]['name']} (" . convert_friendly_interface_to_friendly_descr(substr($pools[$p]['name'], 5)) . ")";
                    $pools[$p]['mystate'] = $data[$f+7];
                    $pools[$p]['peerstate'] = $data[$f+14];
                    $pools[$p]['mydate'] = $data[$f+10];
                    $pools[$p]['mydate'] .= " " . $data[$f+11];
                    $pools[$p]['peerdate'] = $data[$f+17];
                    $pools[$p]['peerdate'] .= " " . $data[$f+18];
                    $p++;
                    $i++;
                    continue 3;
                case "lease":
                    $leases[$l]['ip'] = $data[$f+1];
                    $leases[$l]['type'] = "dynamic";
                    $f = $f+2;
                    break;
                case "starts":
                    $leases[$l]['start'] = $data[$f+2];
                    $leases[$l]['start'] .= " " . $data[$f+3];
                    $f = $f+3;
                    break;
                case "ends":
                    $leases[$l]['end'] = $data[$f+2];
                    $leases[$l]['end'] .= " " . $data[$f+3];
                    $f = $f+3;
                    break;
                case "tstp":
                    $f = $f+3;
                    break;
                case "tsfp":
                    $f = $f+3;
                    break;
                case "atsfp":
                    $f = $f+3;
                    break;
                case "cltt":
                    $f = $f+3;
                    break;
                case "binding":
                    switch($data[$f+2]) {
                        case "active":
                            $leases[$l]['act'] = "active";
                            break;
                        case "free":
                            $leases[$l]['act'] = "expired";
                            $leases[$l]['online'] = "offline";
                            break;
                        case "backup":
                            $leases[$l]['act'] = "reserved";
                            $leases[$l]['online'] = "offline";
                            break;
                    }
                    $f = $f+1;
                    break;
                case "next":
                    /* skip the next binding statement */
                    $f = $f+3;
                    break;
                case "rewind":
                    /* skip the rewind binding statement */
                    $f = $f+3;
                    break;
                case "hardware":
                    $leases[$l]['mac'] = $data[$f+2];
                    /* check if it's online and the lease is active */
                    if (in_array($leases[$l]['ip'], $arpdata_ip)) {
                        $leases[$l]['online'] = 'online';
                    } else {
                        $leases[$l]['online'] = 'offline';
                    }
                    $f = $f+2;
                    break;
                case "client-hostname":
                    if ($data[$f + 1] != '') {
                        $leases[$l]['hostname'] = preg_replace('/"/','',$data[$f + 1]);
                    } else {
                        $hostname = gethostbyaddr($leases[$l]['ip']);
                        if ($hostname != '') {
                            $leases[$l]['hostname'] = $hostname;
                        }
                    }
                    $f = $f+1;
                    break;
                case "uid":
                    $f = $f+1;
                    break;
          }
          $f++;
        }
        $l++;
        $i++;
        /* slowly chisel away at the source array */
        array_shift($leases_content);
    }
    /* remove the old array */
    unset($lease_content);

    if (count($leases) > 0) {
        $leases = remove_duplicate($leases,"ip");
    }

    if (count($pools) > 0) {
        $pools = remove_duplicate($pools,"name");
        asort($pools);
    }

    $macs = [];
    foreach ($leases as $i => $this_lease) {
        if (!empty($this_lease['mac'])) {
            $macs[$this_lease['mac']] = $i;
        }
    }

    foreach (dhcpd_staticmap() as $static) {
        if (!isset($static['ipaddr'])) {
            continue;
        }

        $slease = array();
        $slease['ip'] = $static['ipaddr'];
        $slease['type'] = 'static';
        $slease['mac'] = $static['mac'];
        $slease['start'] = '';
        $slease['end'] = '';
        $slease['hostname'] = $static['hostname'];
        $slease['descr'] = $static['descr'];
        $slease['act'] = 'static';
        $slease['online'] = in_array(strtolower($slease['mac']), $arpdata_mac) ? 'online' : 'offline';

        if (isset($macs[$slease['mac']])) {
            /* update lease with static data */
            foreach ($slease as $key => $value) {
                if (!empty($value)) {
                    $leases[$macs[$slease['mac']]][$key] = $slease[$key];
                }
            }
        } else {
            $leases[] = $slease;
        }
    }

    if (isset($_GET['order']) && in_array($_GET['order'], ['int', 'ip', 'mac', 'hostname', 'descr', 'start', 'end', 'online', 'act'])) {
        $order = $_GET['order'];
    } else {
        $order = 'ip';
    }

    usort($leases,
        function ($a, $b) use ($order) {
            $cmp = ($order === 'ip') ? 0 : strnatcasecmp($a[$order], $b[$order]);
            if ($cmp === 0) {
                $cmp = ipcmp($a['ip'], $b['ip']);
            }
            return $cmp;
        }
    );
?>
<table class="table table-striped table-condensed">
  <thead>
    <tr>
      <th><?= gettext("IP address"); ?></th>
      <th><?= gettext("Hostname"); ?></th>
      <th><?= gettext("Lease type"); ?></th>
      <th><?= gettext("Status"); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php
    foreach ($leases as $data) :
      // Skip rows where $data['act'] is "expired"
      if ($data['act'] == "expired") {
        continue;
      }

      if (($data['act'] == "active") || ($data['act'] == "static") || ($_GET['all'] == 1)) {
        if ($data['act'] != "active" && $data['act'] != "static") {
          $fspans = "<span class=\"gray\">";
          $fspane = "</span>";
        } else {
          $fspans = $fspane = "";
        }

        $lip = ip2ulong($data['ip']);
        if ($data['act'] == "static") {
          foreach ($config['dhcpd'] as $dhcpif => $dhcpifconf) {
            if (is_array($dhcpifconf['staticmap'])) {
              foreach ($dhcpifconf['staticmap'] as $staticent) {
                if ($data['ip'] == $staticent['ipaddr']) {
                  $data['if'] = $dhcpif;
                  break;
                }
              }
            }
            /* exit as soon as we have an interface */
            if ($data['if'] != "")
              break;
          }
        } else {
          foreach ($config['dhcpd'] as $dhcpif => $dhcpifconf) {
            if (($lip >= ip2ulong($dhcpifconf['range']['from'])) && ($lip <= ip2ulong($dhcpifconf['range']['to']))) {
              $data['if'] = $dhcpif;
              break;
            }
          }
        }
      }
    ?>
      <tr>
        <td><?= $data['ip']; ?></td>
        <td><?= $data['hostname']; ?></td>
        <td><?= $data['act']; ?></td>
        <td>
          <i class="fa fa-<?= $data['online'] == 'online' ? 'signal' : 'ban'; ?>" title="<?= $data['online']; ?>" data-toggle="tooltip"></i>
        </td>
      </tr>
    <?php
    endforeach;
    ?>
    <?php if (empty($leases)) : ?>
      <tr>
        <td colspan="4"><?= gettext("No leases file found. Is the DHCP server active"); ?></td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

