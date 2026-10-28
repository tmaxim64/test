<?php
//OLD
set_include_path('/opt/epic/include');

$debuglevel = 0; // Level of debug, 0 is nothing, 9 is everything
$webenabled = 1; // All webpages have this set to 1, background processes set to 0
$keepsession = 1;
$usedbprod = 1;
$bypassperm = 0;
ob_start(); // Start output buffering

include_once 'begin.php'; // Core EPIC initialization
include_once 'header.php'; // EPIC standard header framework
?>

<?php
// Main code here
//===================================================================
echo $func->import_js("/js/lanmanager.js");
echo $func->import_js("/js/htmx.min.js");
echo $func->import_js("/js/utils.js");

$toolinfo = "LAN Management";

$tool_name = empty($pageinfo['name']) ? $pageinfo['self'] : $pageinfo['name'];

$ldap = new LDAP($debug);
$adservers = $config['ad.server.rw'];
$rc = $ldap->connect("{$config['ad.user.rw']}@{$adforest}", $config['ad.pass.rw'], $adservers);

$normaluser = $ldap->get_exchextattr_from_samaccountname($adbasedn, $userinfo['username'], 26);

$snow = new ServiceNow($debug);
$snowAuth = $snow->authenticate($config['snow.host'], $config['snow.user'], $config['snow.pass']);

$snmp = new My\SNMP($debug, array('community.ro' => $config['network.snmp.ro']));
$ssh = new SSH();

foreach ($userinfo['privilege'] as $key => $value) {
   if (preg_match('/lan.manager/', $key)) {
      $split = explode('.', $key);
      if ($split[2])
         $permissionList[] = $split[2];
   }
}
$permission = implode(' ', $permissionList);

$adGroups = array(
   'CN=CP-GLB-LAN-Mfg_Access,OU=Groups,OU=ManagedLAN,OU=Enterprise,OU=Resources,DC=emrsn,DC=org' => 'CP-GLB-LAN-Mfg_Access',
   'CN=CP-GLB-LAN-EDU_Access,OU=Groups,OU=ManagedLAN,OU=Enterprise,OU=Resources,DC=emrsn,DC=org' => 'CP-GLB-LAN-EDU_Access',
   'CN=CP-GLB-LAN-Con_Access,OU=Groups,OU=ManagedLAN,OU=Enterprise,OU=Resources,DC=emrsn,DC=org' => 'CP-GLB-LAN-Con_Access',
   'CN=CP-GLB-LAN-DEV_Access,OU=Groups,OU=ManagedLAN,OU=Enterprise,OU=Resources,DC=emrsn,DC=org' => 'CP-GLB-LAN-DEV_Access',
);

$adGroupLocationCodes = [];

foreach ($adGroups as $adGroup) {
   if (preg_match('/' . $adGroup . '/i', $userinfo['role_ad_group'])) {
      $query = "select ad_group, location_codes from lan_management_rbac_location where user_name = '{$userinfo['username']}' and ad_group = '$adGroup'";
      $dbResult = $db->query($query);

      $locationCodes = json_decode($dbResult[$adGroup]['location_codes'], true);

      foreach ($locationCodes as $locationCode) {
         $adGroupLocationCodes[$adGroup][$locationCode] = $locationCode;
      }
   }
}

// This is to  support non-SD&O Local IT people who need access to only certain locations. 
// In case a new location is needed it just needs to be added to the restrictedLocations array
if (preg_match('/limited/i', $permission)) {
   $restrictedLocations = array('L01927', 'L01944');
}

if (preg_match('/env/i', $permission)) {
   $restrictedLocations = getSnowLocations($userinfo['site']);

   foreach ($adGroupLocationCodes as $locationCodes) {
      foreach ($locationCodes as $locationCode) {
         $restrictedLocations[] = $locationCode;
      }
   }
}

$tabs = array(
   "Favorites" => [
      "title" => "Favorites",
      "icon" => "star icon",
      "function" => [
         "show" => "show_favorites_void"
      ]
   ],
   'AllSites' => array(
      'title' => 'All Locations',
      'icon' => 'list layout icon',
      'function' => array(
         'show' => 'showAllSites',
         'Search MAC' => 'macLookup',
         'Submit Request' => 'requestLowImpact',
      )
   ),
   'Site' => array(
      'title' => 'Location',
      'icon' => 'list layout icon',
      'function' => array(
         'show' => 'showSite',
         'Authorize' => 'authorizeEndpoint',
         'Delete Authorization' => 'deleteAuthorization',
         'Delete selected' => 'deleteAuthorizationBulk',
         'Search MAC' => 'macLookup',
         'Apply Banners' => 'applyBanners',
         'Save' => 'reviewEndpoint'
      )
   ),
   'Switch' => array(
      'title' => 'Switch',
      'icon' => 'list layout icon',
      'function' => array(
         'show' => 'showSwitch',
         'Search MAC' => 'macLookup',
         'Update ServiceNow' => 'updateServiceNow',
         'Configure Interface' => 'configureInterface',
         'Configure Port-Channel' => 'configurePortChannel',
         'Apply Banner' => 'applyBanner',
         'Create VLAN(s)' => 'createVLANs',
         'Create Port Channel' => 'createPortChannel',
         "Apply Copy Port Config" => "apply_copy_port_config_void",
      )
   ),
   'Admin' => array(
      'title' => 'Admin',
      'icon' => 'edit layout icon',
      'function' => array(
         'show' => 'showAdmin',
         'Search MAC' => 'macLookup',
         'Submit Change' => 'changeToLowImpact',
      )
   ),
   'Log' => array(
      'title' => 'Change Logs',
      'icon' => 'archive icon',
      'function' => array(
         'show' => 'showLog',
         'Search MAC' => 'macLookup',
      )
   ),
);

// SNMP OID
$oids = array(
   'int' => array(
      'ifdescr' => '.1.3.6.1.2.1.2.2.1.2',
      'ifadminstatus' => '.1.3.6.1.2.1.2.2.1.7',
      'ifoperstatus' => '.1.3.6.1.2.1.2.2.1.8',
      'iferrdisabled' => '.1.3.6.1.4.1.9.9.548.1.3.1.1.2',
      'ifalias' => '.1.3.6.1.2.1.31.1.1.1.18',
      'ifspeed' => '.1.3.6.1.2.1.2.2.1.5',
      'ifvlan' => '.1.3.6.1.4.1.9.9.68.1.2.2.1.2',
      'ifvoicevlan' => '.1.3.6.1.4.1.9.9.68.1.5.1',
      'ifnativevlan' => '.1.3.6.1.4.1.9.9.46.1.6.1.1.5',
      'ifmode' => '.1.3.6.1.4.1.9.9.46.1.6.1.1.13',
      'iftrunkvlan' => '.1.3.6.1.4.1.9.9.46.1.6.1.1.4',
      'ifduplex' => '.1.3.6.1.2.1.10.7.2.1.19',
      'ifcdpid' => '.1.3.6.1.4.1.9.9.23.1.2.1.1.6',
      'ifcdpdevice' => '.1.3.6.1.4.1.9.9.23.1.2.1.1.8',
   ),
   'vlan' => '.1.3.6.1.4.1.9.9.46.1.3.1.1.4',
   'sysdescr' => '.1.3.6.1.2.1.1.1',
   'portChannelMembersList' => '1.3.6.1.4.1.9.9.285.1.1.1.1.9'
);

//https://snmp.cloudapps.cisco.com/Support/SNMP/do/BrowseOID.do?objectInput=1.3.6.1.4.1.9.9.46.1.6.1.1.13&translate=Translate&submitValue=SUBMIT&submitClicked=true
$portModes = array(
   "1" => "trunk",
   "2" => "access",
   "3" => "trunk desirable",
   "4" => "unconfigured",
   "5" => "trunk",
);

$cliCommands = array(
   '' => 'Select command',
   'clear counters' => 'clear counters',
   'ping filter' => 'ping',
   'trace filter' => 'trace',
   'test cable-diagnostics tdr interface filter' => 'test cable-diagnostics tdr interface',
   'show auth sessions' => 'show auth sessions',
   'show aaa servers' => 'show aaa servers',
   'show arp filter' => 'show arp',
   'show authentication sessions' => 'show authentication sessions',
   'show authentication sessions interface filter' => 'show authentication sessions interface',
   'show authentication session interface filter details' => 'show authentication session interface details',
   'show cable-diagnostics tdr interface filter' => 'show cable-diagnostics tdr interface',
   'show cdp neighbors' => 'show cdp neighbors',
   'show clock' => 'show clock',
   'show controllers' => 'show controllers',
   'show device-tracking database' => 'show device-tracking database',
   'show device-tracking database interface filter' => 'show device-tracking database interface',
   'show device-tracking database detail' => 'show device-tracking database detail',
   'show environment' => 'show environment',
   'show environment all' => 'show environment all',
   'show etherchannel filter summary' => 'show etherchannel summary',
   'show interface filter' => 'show interface',
   'show interfaces' => 'show interfaces',
   'show interfaces counters' => 'show interfaces counters',
   'show interfaces counters error' => 'show interfaces counters error',
   'show interfaces counters etherchannel' => 'show interfaces counters etherchannel',
   'show interfaces counters protocol status' => 'show interfaces counters protocol status',
   'show interfaces counters trunk' => 'show interfaces counters trunk',
   'show interface description' => 'show interface description',
   'show interface etherchannel' => 'show interface etherchannel',
   'show interfaces status' => 'show interfaces status',
   'show interface summary' => 'show interface summary',
   'show interface filter transceiver detail' => 'show interface transceiver detail',
   //'show inventory'                       => 'show inventory',
   'show ip device tracking all' => 'show ip device tracking all',
   'show ip device tracking interface filter' => 'show ip device tracking interface',
   'show ip eigrp neighbors' => 'show ip eigrp neighbors',
   'show ip eigrp neighbor' => 'show ip eigrp neighbor',
   'show ip eigrp topology' => 'show ip eigrp topology',
   'show ip interface brief' => 'show ip interface brief',
   'show ip route' => 'show ip route',
   'show log' => 'show log',
   'show mac address-table' => 'show mac address-table',
   'show mac address-table address filter' => 'show mac address-table address',
   'show mac address-table interface filter' => 'show mac address-table interface',
   'show power inline filter' => 'show power inline',
   'show radius server-group all' => 'show radius server-group all',
   'show running-config' => 'show running-config',
   'show startup-config' => 'show startup-config',
   'show switch' => 'show switch',
   'show switch detail' => 'show switch detail',
   'show switch stack-mode' => 'show switch stack-mode',
   'show switch stack-ports' => 'show switch stack-ports',
   'show switch stack-ring speed' => 'show switch stack-ring speed',
   'show tech-support' => 'show tech-support',
   'show version' => 'show version',
   'show vlan' => 'show vlan',
);

$authGroups = array(
   'Wired_Whitelist_Permanent' => 'Permanent',
   'Wired_Whitelist_Temporary' => 'Temporary'
);

$authDetails = array(
   'authGroup' => $input->get('authGroup', 'alphanumeric'),
   'authDesc' => $input->get('authDesc', 'all'),
   'authPhone' => ($input->get('authPhone', 'numeric')) ? 1 : 0,
   'authLocation' => $input->get('authLocation', 'alphanumeric'),
   'endpoint' => $input->get('endpoint', 'all'),
);


if (preg_match("/fulladmin/i", $permission) || $userinfo['privilege']['everything']) {
   $adGroups['CN=Managed LAN Service Local IT,OU=AAA_ISE,OU=ISE,OU=Groups,OU=Forest Administration,DC=emrsn,DC=org'] = 'Managed LAN Service Local IT';
}

// Establish connection to ise devices 
$query = "select * from landevmgmt_admin where enabled = 1";
$isedevices = $db->query($query);

foreach ($isedevices as $isedevice) {
   $isedevs[$isedevice['platform']][] = array('ip' => $isedevice['ip'], 'name' => $isedevice['name']);
}

$ise = new ISE($debug);

foreach ($isedevs as $type => $device) {
   foreach ($device as $name => $info) {
      if (preg_match('/china/i', $type)) {
         $ise->addDevice($info['name'], $info['ip'], 'ise', $config['ise.china.ers.user'], $config['ise.china.ers.pass']);
      } else {
         $ise->addDevice($info['name'], $info['ip'], 'ise', $config['ise.ers.user'], $config['ise.ers.pass']);
      }
   }
}

// Get existing locations
// $query = 'SELECT  site, region, count(*),JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.location")) as location ' .
//    'FROM lan_switch ' .
//    'WHERE service LIKE "Managed"  GROUP BY site';

/**
 * So, the issue is that json extract fails due to empty sys locations, this improves the issues, can you please check it?
 */
$query = 'SELECT 
              site, 
              region, 
              COUNT(*) as count, 
              JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.location")) AS location 
          FROM lan_switch 
          WHERE 
              service LIKE "Managed" AND 
              JSON_VALID(details) AND 
              JSON_EXTRACT(details, "$.sysinfo.location") IS NOT NULL 
          GROUP BY site';

$locations = $db->query($query);

$selectLocation[] = 'Select Location';

foreach ($locations as $location) {
   if (preg_match('/limited|env/i', $permission) && !preg_grep('/' . substr($location['site'], 2) . '/i', $restrictedLocations)) {
      continue;
   }
   $selectLocation[$location['site']] = $location['site'];

   if ($location['location'] && $location['location'] != 'null') {
      $selectLocation[$location['site']] .= " (" . $location['location'] . ")";
   }
}

// $query = 'SELECT  name , site, region, ipaddress ,mode,  ' .
//    'JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.location")) as location, ' .
//    'JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.sysLocation")) as sysLocation ' .
//    'FROM lan_switch ' .
//    'WHERE service LIKE "Managed" AND enabled = 1';

$query = 'SELECT 
              name, 
              site, 
              region, 
              ipaddress, 
              mode, 
              JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.location")) as location, 
              JSON_UNQUOTE(JSON_EXTRACT(details, "$.sysinfo.sysLocation")) as sysLocation 
          FROM lan_switch 
          WHERE 
              service LIKE "Managed" AND 
              enabled = 1 AND 
              JSON_VALID(details) AND 
              JSON_EXTRACT(details, "$.sysinfo.location") IS NOT NULL AND 
              JSON_EXTRACT(details, "$.sysinfo.sysLocation") IS NOT NULL';

$switches = $db->query($query);

$selectSwitch[] = 'Select Switch';

foreach ($switches as $id => $switch) {
   // Restrict the locations for this type of permission
   if (preg_match('/limited|env/i', $permission) && !preg_grep('/' . substr($switch['site'], 2) . '/i', $restrictedLocations)) {
      continue;
   }

   preg_match_all('/MFG|DEV|EDU|CON/', $switch['sysLocation'], $switchPurpose);

   // We need to limit access to switches based on purpose
   if (preg_match('/env/i', $permission)) {

      if (!$switchPurpose[0]) {
         unset($switches[$id]);
         continue;
      }
      // This is just an extra security layer as  SWC|SWG|SWT switches should never be tagged with env values 
      if (!preg_match("/envcon/i", $permission)) {
         if (preg_match('/SWC|SWG|SWT/i', $switch['name'])) {
            unset($switches[$id]);
            continue;
         }
      } else {
         if (preg_match('/SWG|SWT/i', $switch['name'])) {
            unset($switches[$id]);
            continue;
         }
      }

      $checkperm = preg_replace("/env/", "", (implode("|", $permissionList)));
      // Check if permissions mathes with switch purpose
      if (!preg_match("/$checkperm/i", strtolower(implode(',', $switchPurpose[0])))) {
         unset($switches[$id]);
         $unsetIds[] = $id;
         continue;
      }

   }

   // need to know which switches need to be ignored
   if (in_array($id, $unsetIds))
      continue;

   $selectSwitch[$switch['name']] = $switch['name'];

   if ($switch['location'] && $switch['location'] != 'null') {
      $selectSwitch[$switch['name']] .= " (" . $switch['location'] . ")";
      $allowedSwitches[$switch['ipaddress']] = $switch['name'];
   }
}

// $allowedSwitches = array_keys($selectSwitch);
// unset($allowedSwitches[0]); // Remove 'Select Switch' entry

$selectIfConfig = array(
   'adminStatus' => array('', 'no shutdown', 'shutdown'),
   'speed' => array('', '10', '100', '1000', 'auto'),
   'duplex' => array('', 'auto', 'half', 'full')
);

$splunk = new Splunk($debug, array('token' => $config['splunk.token']));

$ad_group_alert_emails = [
   'kreyes' => 'karl.reyes@emerson.com',
   'niels' => 'Niels.vanderGraaf@emerson.com',
   'kris' => 'kris.wicklund@emerson.com',
   'derek' => 'derek.schmidt@copeland.com'
];

$page_url_str = $pageinfo["self"];
$htmx_page_url_str = $page_url_str . "?htmx=1";

if (!$pageinfo["name"]) {
   $tool_name_str = "lan.manager";
} else {
   $tool_name_str = "lan.manager";
}

$make_device_favorite_base_hyperlink_str = "/lan-manager.php?tab=Favorites";
$switch_tab_base_hyperlink_str = "/lan-manager.php?tab=Switch&searchSwitch=%s";
$location_tab_base_hyperlink_str = "/lan-manager.php?tab=Site&searchSite=%s";

$global = array(
   "htmx_page_url_str" => $htmx_page_url_str,
   "make_device_favorite_base_hyperlink_str" => $make_device_favorite_base_hyperlink_str,
   "switch_tab_base_hyperlink_str" => $switch_tab_base_hyperlink_str,
   "location_tab_base_hyperlink_str" => $location_tab_base_hyperlink_str,
   'emails' => $ad_group_alert_emails,
   'db' => $db,
   'html' => $html,
   'input' => $input,
   'install_status' => '1', // Remove or keep this?
   'ldap' => $ldap,
   'normal_user_name' => $normaluser,
   'adbasedn' => $adbasedn,
   'adGroups' => $adGroups,
   'func' => $func,
   'snmp' => $snmp,
   'splunk' => $splunk,
   'config' => $config,
   'ssh' => $ssh,
   'snow' => $snow,
   'mail' => $mail,
   'userinfo' => $userinfo,
   "username_str" => $userinfo["username"],
   "tool_name_str" => $tool_name_str,
   "tool_name" => $tool_name_str,
   'permission' => $permission,
   'permissionList' => $permissionList,
   'restrictedLocations' => $restrictedLocations,
   'tabs' => $tabs,
   'tab' => ($input->get('tab', 'all')) ? $input->get('tab', 'all') : 'AllSites',
   'submit' => $input->get('submit', 'all'),
   'siteSection' => ($input->get('siteSection', 'all')) ? $input->get('siteSection', 'all') : 'Switches',
   'adminSection' => ($input->get('adminSection', 'all')) ? $input->get('adminSection', 'all') : 'Sync With Catalyst Center',
   'searchSite' => $input->get('searchSite', 'alphanumeric') ? $input->get('searchSite', 'alphanumeric') : $authDetails['authLocation'],
   'searchSwitch' => $input->get('searchSwitch', 'all'),
   'connect' => $input->get('connect', 'all'),
   'switchInterface' => $input->get('switchInterface', 'all'),
   'currentIfConfig' => json_decode(base64_decode($input->get('currentIfConfig', 'all')), true),
   'modal' => $input->get('modal', 'all'),
   'endpoint' => $input->get('endpoint', 'all'),
   'searchMac' => substr(preg_replace('~(..)(?!$)\.?~', '\1:', strtoupper($input->get('searchMac', 'alphanumeric'))), 0, 17),
   'authGroups' => $authGroups,
   'authDetails' => $authDetails,
   'ise' => $ise,
   'isedevs' => $isedevs,
   'locations' => $locations,
   'selectLocation' => $selectLocation,
   'selectSwitch' => $selectSwitch,
   'selectIfConfig' => $selectIfConfig,
   'switches' => $switches,
   'cliCommands' => $cliCommands,
   'oids' => $oids,
   'portModes' => $portModes,
   'location_banner' => $input->get("location_banner", 'all'),
   'debug' => $debug,
   'allowedSwitches' => $allowedSwitches
);

if ($_GET["htmx"]) {
   ob_end_clean(); // Discards the previous buffer contents and displays only the current output from call_user_func

   $func_name_str = $_POST["func_name"];

   call_user_func($func_name_str);

   include_once "end.php"; // Core graceful termination
   exit;
} else {
   ob_end_flush(); // Discards the previous buffer contents and displays only the current output from call_user_func
}

print $html->divbox('toolinfo', $toolinfo);

// We need an independent form to manage endpoints 
print $html->startform();
print $html->input_text('searchMac', $searchMac, 17, 20, array('placeholder' => 'MAC Address'));
print $html->submit('submit', 'Search MAC', array('color' => 'gray'));
print $html->hidden_text('tab', 'Site');
print "<br><br>";
print $html->endform();

displayTabs();

$ldap->disconnect();

//====================================================================

include_once 'footer.php'; // EPIC standard footer framework
include_once 'end.php'; // Core graceful termination

?>
<?php
// Supporting functions here 
//====================================================================
function displayTabs()
{
   global $global;

   $tabs = $global['tabs'];
   $cTab = $global['tab'];
   $submit = $global['submit'];

   // Tabular menu
   print "<div class='ui top attached tabular menu'>";

   foreach ($tabs as $tab => $info) {
      $icon = $info['icon'];

      if ($tab == $cTab) {
         print "<a class='active item' data-tab=$tab><i class='$icon'></i>" . $info['title'] . '</a>';
      } else {
         print "<a class='item' data-tab=$tab><i class='$icon'></i>" . $info['title'] . '</a>';
      }
   }

   print "</div>";

   foreach ($tabs as $tab => $info) {
      $active = '';

      if ($tab == $cTab) {
         $active = 'active';
      }

      print "<div class='ui bottom attached $active tab segment' style='min-height: 500px; overflow-x:auto;' data-tab=$tab>";

      print "<br>";

      if ($active) {
         if ($submit && $info['function'][$submit])
            call_user_func($info['function'][$submit]);
      }

      if ($info['function']['show']) {
         call_user_func($info['function']['show']);
      }

      print "</div>";

   }
}

function show_favorites_void()
{
   global $global;

   $db = $global["db"];
   $html = $global["html"];
   $func = $global["func"];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];
   $location_arr = $global["locations"];
   $switch_tab_base_hyperlink_str = $global["switch_tab_base_hyperlink_str"];
   $make_device_favorite_base_hyperlink_str = $global["make_device_favorite_base_hyperlink_str"];
   $location_tab_base_hyperlink_str = $global["location_tab_base_hyperlink_str"];

   $action_str = $_GET["action"];
   $level_str = $_GET["level"];

   $status_bool = null;
   $status_str = "";

   $device_section_active_bool = false;
   $location_section_active_bool = false;

   $db_req_str = "select  name as hostname, site as site_code, ipaddress as ip_address, " .
      "JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.location')) as location, " .
      "JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation from lan_switch where enabled = 1";
   $db_switch_arr = $db->query($db_req_str);

   $skip_to_table_bool = false;

   if (preg_match("/device/i", $level_str)) {
      $device_section_active_bool = true;
      $location_section_active_bool = false;

      $hostname_str = trim($_GET["hostname"]);
      $lower_hostname_str = strtolower($hostname_str);

      $lower_hostname_arr = [];

      foreach ($db_switch_arr as $value_arr) {
         $local_lower_hostname_str = strtolower($value_arr["hostname"]);
         $lower_hostname_arr[$local_lower_hostname_str] = $local_lower_hostname_str;
      }

      if (!in_array($lower_hostname_str, $lower_hostname_arr)) {
         echo $html->error_message("Could not validate router name.", "Error");
         $skip_to_table_bool = true;
      }

      if (preg_match("/add/i", $action_str) && $skip_to_table_bool === false) {
         $status_bool = add_device_to_favorites_bool($lower_hostname_str);

         if ($status_bool === true) {
            $status_str = "Device {$hostname_str} was added to favorites.";
         } else {
            $status_str = "Device {$hostname_str} could not be added to favorites.";
         }
      }

      if (preg_match("/remove/i", $action_str) && $skip_to_table_bool === false) {
         $status_bool = remove_device_from_favorites_bool($lower_hostname_str);

         if ($status_bool === true) {
            $status_str = "Device {$hostname_str} was removed from favorites.";
         } else {
            $status_str = "Device {$hostname_str} could not be removed from favorites.";
         }
      }
   }

   if (preg_match("/location/i", $level_str)) {
      $device_section_active_bool = false;
      $location_section_active_bool = true;

      $site_code_str = trim($_GET["site_code"]);
      $lower_site_code_str = strtolower($site_code_str);
      $upper_site_code_str = strtoupper($site_code_str);

      if (preg_match("/add/i", $action_str)) {
         $status_bool = add_site_to_favorites_bool($lower_site_code_str);

         if ($status_bool === true) {
            $status_str = "Site {$upper_site_code_str} was added to favorites.";
         } else {
            $status_str = "Site {$upper_site_code_str} could not be added to favorites";
         }
      }

      if (preg_match("/remove/i", $action_str)) {
         $status_bool = remove_site_from_favorites_bool($lower_site_code_str);

         if ($status_bool === true) {
            $status_str = "Site {$upper_site_code_str} was removed from favorites.";
         } else {
            $status_str = "Site {$upper_site_code_str} could not be removed from favorites.";
         }
      }
   }

   if ($status_bool === true) {
      echo "<div style='margin-bottom: 10px;'>"; {
         echo $html->success_message($status_str, "Success");
      }
      echo "</div>";
   } else if ($status_bool === false) {
      echo "<div style='margin-bottom: 10px;'>"; {
         echo $html->error_message($status_str, "Error");
      }
      echo "</div>";
   }

   echo "<div style='display: flex; flex-direction: row; gap: 10px; margin-bottom: 10px;'>"; {
      $device_section_button_props = [
         "color" => "teal",
      ];

      $location_section_button_props = [
         "color" => "gray",
      ];

      if ($device_section_active_bool === true && $location_section_active_bool === false) {
         $device_section_button_props = [
            "color" => "teal",
         ];

         $location_section_button_props = [
            "color" => "gray",
         ];
      }

      if ($device_section_active_bool === false && $location_section_active_bool === true) {
         $device_section_button_props = [
            "color" => "gray",
         ];

         $location_section_button_props = [
            "color" => "teal",
         ];
      }

      echo $html->submit("section_device_favorite_button", "Device(s)", $device_section_button_props);
      echo $html->submit("section_location_favorite_button", "Location(s)", $location_section_button_props);
   }
   echo "</div>";

   echo "<div>"; {
      $device_section_style_str = "style='display: block;'";
      $location_section_style_str = "style='display: none;'";

      if ($device_section_active_bool === true && $location_section_active_bool === false) {
         $device_section_style_str = "style='display: block;'";
         $location_section_style_str = "style='display: none;'";
      }

      if ($device_section_active_bool === false && $location_section_active_bool === true) {
         $device_section_style_str = "style='display: none;'";
         $location_section_style_str = "style='display: block;'";
      }

      $db_req_str = "select user_name, preferences from user where user_name = '{$username_str}' ";
      $db_res_arr = $db->query($db_req_str);

      $preference_arr = json_decode($db_res_arr[$username_str]["preferences"], true);

      echo "<div id='favorites_section_device' $device_section_style_str>"; {
         $local_preference_arr = $preference_arr[$tool_name_str]["favorites"]["device"];
         $favorite_device_arr = array_keys($local_preference_arr);

         $html_table_arr = [
            "options" => [
               "table.id" => "favorites_device_table",
               "datatable" => true,
            ],
            "column" => [
               "Unfavorite" => "remove",
               "Site Code" => "site_code",
               "Location" => "location",
               "Hostname" => "hostname",
               "IP Address" => "ip_address"
            ],
            "row" => []
         ];

         foreach ($db_switch_arr as $value_arr) {
            $local_lower_hostname_str = strtolower($value_arr["hostname"]);

            if (!in_array($local_lower_hostname_str, $favorite_device_arr)) {
               continue;
            }

            $switch_redirect_link_str = sprintf($switch_tab_base_hyperlink_str, $value_arr["hostname"]);

            $hyperlink_props_arr = [
               "level" => "level=device",
               "action" => "action=remove",
               "hostname" => "hostname={$value_arr['hostname']}",
            ];

            $unfavorite_html_str = "<div style='display: flex; justify-content: center; align-items:center;'>"; {
               $unfavorite_device_hyperlink_str = $make_device_favorite_base_hyperlink_str . "&" . implode("&", $hyperlink_props_arr);

               $unfavorite_html_str .= "<a style='color: inherit; text-decoration: none;' href='{$unfavorite_device_hyperlink_str}'><i class='yellow star icon'></i></a>";
            }
            $unfavorite_html_str .= "</div>";

            $location_redirect_link_str = sprintf($location_tab_base_hyperlink_str, $value_arr["site_code"]);

            $html_table_arr["row"][] = [
               "remove" => $unfavorite_html_str,
               "site_code" => "<a href='{$location_redirect_link_str}'>{$value_arr["site_code"]}</a>",
               "location" => $value_arr["location"],
               "hostname" => "<a href='{$switch_redirect_link_str}' >{$value_arr['hostname']}</a>",
               "ip_address" => $value_arr["ip_address"],
            ];
         }

         echo $html->table($html_table_arr["row"], $html_table_arr["column"], $html_table_arr["options"]);
      }
      echo "</div>";

      echo "<div id='favorites_section_location' $location_section_style_str>"; {
         $local_preference_arr = $preference_arr[$tool_name_str]["favorites"]["location"];
         $favorite_location_arr = array_keys($local_preference_arr);

         $html_table_arr = [
            "options" => [
               "table.id" => "favorites_location_table",
               "datatable" => true,
            ],
            "column" => [
               "Unfavorite" => "remove",
               "Site Code" => "site_code",
               "Location" => "location",
            ],
            "row" => []
         ];

         foreach ($favorite_location_arr as $favorite_location_str) {
            $upper_site_code_str = strtoupper($favorite_location_str);

            $location_redirect_link_str = sprintf($location_tab_base_hyperlink_str, $upper_site_code_str);

            $hyperlink_props_arr = [
               "level" => "level=location",
               "action" => "action=remove",
               "site_code" => "site_code={$favorite_location_str}",
            ];

            $unfavorite_html_str = "<div style='display: flex; justify-content: center; align-items:center;'>"; {
               $unfavorite_device_hyperlink_str = $make_device_favorite_base_hyperlink_str . "&" . implode("&", $hyperlink_props_arr);

               $unfavorite_html_str .= "<a style='color: inherit; text-decoration: none;' href='{$unfavorite_device_hyperlink_str}'><i class='yellow star icon'></i></a>";
            }
            $unfavorite_html_str .= "</div>";

            $html_table_arr["row"][] = [
               "remove" => $unfavorite_html_str,
               "site_code" => "<a href='{$location_redirect_link_str}'>{$upper_site_code_str}</a>",
               "location" => $location_arr[$upper_site_code_str]["location"],
            ];
         }

         echo $html->table($html_table_arr["row"], $html_table_arr["column"], $html_table_arr["options"]);
      }
      echo "</div>";
   }
   echo "</div>";
}

function add_device_to_favorites_bool($_hostname_str)
{
   global $global;

   $db = $global["db"];
   $func = $global["func"];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $status_bool = false;

   $preference_arr = [];

   $db_req_str = "select user_name, preferences from user where user_name = '{$username_str}' ";
   $db_res_arr = $db->query($db_req_str);

   $preference_arr = json_decode($db_res_arr[$username_str]["preferences"], true);

   $preference_arr[$tool_name_str]["favorites"]["device"][$_hostname_str] = $_hostname_str;

   $preference_str = json_encode($preference_arr);
   $preference_str = $db->escape_string($preference_str);

   $db_req_str = "update user set preferences = '{$preference_str}' where user_name = '{$username_str}'";
   $db_res_bool = $db->execute($db_req_str);

   if ($db_res_bool === true) {
      $status_bool = true;
   }

   return $status_bool;
}

function remove_device_from_favorites_bool($_hostname_str)
{
   global $global;

   $db = $global["db"];
   $func = $global["func"];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $status_bool = false;

   $db_req_str = "select user_name, preferences from user where user_name = '{$username_str}' ";
   $db_res_arr = $db->query($db_req_str);

   $preference_arr = json_decode($db_res_arr[$username_str]["preferences"], true);

   unset($preference_arr[$tool_name_str]["favorites"]["device"][$_hostname_str]);

   $preference_str = json_encode($preference_arr);
   $preference_str = $db->escape_string($preference_str);

   $db_req_str = "update user set preferences = '{$preference_str}' where user_name = '{$username_str}'";
   $db_res_bool = $db->execute($db_req_str);

   if ($db_res_bool === true) {
      $status_bool = true;
   }

   return $status_bool;
}

function add_site_to_favorites_bool($_site_code_str)
{
   global $global;

   $db = $global["db"];
   $func = $global["func"];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $status_bool = false;

   $preference_arr = [];

   $db_req_str = "select user_name, preferences from user where user_name = '{$username_str}' ";
   $db_res_arr = $db->query($db_req_str);

   $preference_arr = json_decode($db_res_arr[$username_str]["preferences"], true);

   $preference_arr[$tool_name_str]["favorites"]["location"][$_site_code_str] = $_site_code_str;

   $preference_str = json_encode($preference_arr);
   $preference_str = $db->escape_string($preference_str);

   $db_req_str = "update user set preferences = '{$preference_str}' where user_name = '{$username_str}'";
   $db_res_bool = $db->execute($db_req_str);

   if ($db_res_bool === true) {
      $status_bool = true;
   }

   return $status_bool;
}

function remove_site_from_favorites_bool($_site_code_str)
{
   global $global;

   $db = $global["db"];
   $func = $global["func"];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $status_bool = false;

   $db_req_str = "select user_name, preferences from user where user_name = '{$username_str}' ";
   $db_res_arr = $db->query($db_req_str);

   $preference_arr = json_decode($db_res_arr[$username_str]["preferences"], true);

   unset($preference_arr[$tool_name_str]["favorites"]["location"][$_site_code_str]);

   $preference_str = json_encode($preference_arr);
   $preference_str = $db->escape_string($preference_str);

   $db_req_str = "update user set preferences = '{$preference_str}' where user_name = '{$username_str}'";
   $db_res_bool = $db->execute($db_req_str);

   if ($db_res_bool === true) {
      $status_bool = true;
   }

   return $status_bool;
}

function macLookup()
{

   global $global;

   $db = $global["db"];
   $ise = $global["ise"];
   $html = $global["html"];
   $func = $global["func"];
   $location_arr = $global["selectLocation"];

   $site_code_arr = array_keys($location_arr);

   $ise_dev_arr = $global["isedevs"];
   $endpoint_str = trim(strtolower($global["searchMac"]));

   $endpoint_in_ise_bool = false;
   $endpoint_is_authorized_bool = false;
   $ise_fallback_search_bool = false;

   $db_req_str = "SELECT DISTINCT(LOWER(endpoint)) FROM lan_switch_nac";
   $db_res_arr = $db->query($db_req_str);

   $endpoint_arr = array_keys($db_res_arr);

   if (!in_array($endpoint_str, $endpoint_arr)) {
      $ise_fallback_search_bool = true;
   }

   $db_req_str = "SELECT * FROM lan_switch_nac WHERE endpoint = '{$endpoint_str}'";
   $db_res_arr = $db->query($db_req_str);

   if (!$db_res_arr) {
      $ise_fallback_search_bool = true;
   } else {
      $switch_hostname_str = "";
      $switch_ip_str = "";
      $front_end_section_str = "";
      $site_code_str = "";

      foreach ($db_res_arr as $value_arr) {
         $switch_hostname_str = $value_arr["switch_name"];
         $switch_ip_str = $value_arr["switch_ip"];

         if (preg_match("/failed/i", $value_arr['authentication']) && !preg_match("/permanent|temp/", $value_arr["authorization"])) {
            $front_end_section_str = "Categorize";
         }

         if (
            preg_match("/failed/i", $value_arr['authentication']) &&
            preg_match("/permanent|temp|remediate/", $value_arr["authorization"]) &&
            strtotime($value_arr["last_seen"]) > strtotime($value_arr["updated"])
         ) {
            $front_end_section_str = "Remediate";
         }

         if (preg_match("/permanent|temp/i", $value_arr["authorization"])) {
            $front_end_section_str = "LifeCycle";
         }

         if ($switch_hostname_str) {
            $alt_db_req_str = "SELECT site FROM lan_switch WHERE name LIKE '%{$switch_hostname_str}%' AND " .
               "ipaddress = '{$switch_ip_str}' AND enabled = 1";
            $alt_db_res_arr = $db->query($alt_db_req_str);

            foreach ($alt_db_res_arr as $value_arr) {
               $site_code_str = $value_arr["site"];
            }

            if (!in_array($site_code_str, $site_code_arr)) {
               $site_code_str = "";
            }
         }
      }

      if (!$switch_hostname_str || !$switch_ip_str || !$site_code_str || !$front_end_section_str) {
         $ise_fallback_search_bool = true;
      } else {
         $global["siteSection"] = $front_end_section_str;
         $global["searchSite"] = $site_code_str;
         $global["endpoint"] = $endpoint_str;
      }
   }

   if ($ise_fallback_search_bool === true) {
      // Check if mac alredy belongs to a group - permanent or temp
      foreach ($ise_dev_arr["radius"] as $ise_dev) {
         $endpoint_details_arr[$ise_dev["name"]] = $ise->getEndpointByName($ise_dev["name"], $endpoint_str);

         if ($endpoint_details_arr[$ise_dev["name"]]["ERSEndPoint"]["name"]) {
            $endpoint_in_ise_bool = true;
         }

         $group_id_str = $endpoint_details_arr[$ise_dev["name"]]["ERSEndPoint"]["groupId"];

         if ($group_id_str) {
            $endpoint_group_arr = $ise->getEndpointIdentityGroup($ise_dev["name"], $group_id_str);

            if (preg_match('/Wired_Whitelist_Permanent|Wired_Whitelist_Temporary/i', $endpoint_group_arr["EndPointGroup"]["name"])) {
               $endpoint_is_authorized_bool = true;
            }
         }
      }

      // Check if mac alredy belongs to a group - permanent or temp in China radius
      // Currently what is in china is also in the emr radius - however below code is in prep for when china endpoints will be only authorized in china radius
      foreach ($ise_dev_arr["radius.china"] as $ise_dev) {
         $endpoint_details_arr[$ise_dev["name"]] = $ise->getEndpointByName($ise_dev["name"], $endpoint_str);

         if ($endpoint_details_arr[$ise_dev["name"]]["ERSEndPoint"]["name"]) {
            $endpoint_in_ise_bool = true;
         }

         $group_id_str = $endpoint_details_arr[$ise_dev["name"]]["ERSEndPoint"]["groupId"];

         if ($group_id_str) {
            $endpoint_group_arr = $ise->getEndpointIdentityGroup($ise_dev["name"], $group_id_str);

            if (preg_match('/Wired_Whitelist_Permanent|Wired_Whitelist_Temporary/i', $endpoint_group_arr["EndPointGroup"]["name"])) {
               $endpoint_is_authorized_bool = true;
            }
         }
      }

      if ($endpoint_in_ise_bool === true && $endpoint_is_authorized_bool === true) {
         showDeleteAuthorizationModal();
      } else if (strlen($endpoint_str) == 17) {
         showAuthorizeModal();
      } else {
         print $html->error_message("Invalid MAC Address Format", "Error");
      }
   }
}

function showSite()
{

   global $global;

   $html = $global['html'];
   $input = $global['input'];
   $db = $global['db'];
   $tab = $global['tab'];
   $userinfo = $global['userinfo'];
   $func = $global["func"];

   $siteSection = $global['siteSection'];
   $selectLocation = $global['selectLocation'];

   $searchSite = $global['searchSite'];

   $color['Switches'] = (preg_match('/switches/i', $siteSection)) ? 'teal' : 'gray';
   $color['Categorize'] = (preg_match('/categorize/i', $siteSection)) ? 'teal' : 'gray';
   $color['Remediate'] = (preg_match('/remediate/i', $siteSection)) ? 'teal' : 'gray';
   $color['LifeCycle'] = (preg_match('/lifecycle/i', $siteSection)) ? 'teal' : 'gray';
   $color['SecurityViolation'] = (preg_match('/SecurityViolation/i', $siteSection)) ? 'teal' : 'gray';

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print "<div class='ui two column grid'>";
   print "<div class='column'>";
   print $html->select(
      'searchSite',
      $selectLocation,
      $searchSite,
      array('class' => 'ui fluid search selection dropdown')
   );
   print "</div>";
   print "<div class='column'>";
   print $html->hidden_text('tab', 'Site');
   print $html->submit('search', 'Search', array('color' => 'gray'));
   print "</div></div>";
   print $html->endform();

   if ($searchSite) {
      print "<br><br>";
      print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
      print $html->submit('siteSection', 'Switches', array('color' => $color['Switches']));
      print $html->submit('siteSection', 'Categorize', array('color' => $color['Categorize']));
      print $html->submit('siteSection', 'Remediate', array('color' => $color['Remediate']));
      print $html->submit('siteSection', 'LifeCycle', array('color' => $color['LifeCycle']));
      print $html->submit('siteSection', 'SecurityViolation', ['color' => $color['SecurityViolation']]);
      print "<br>";

      print $html->hidden_text('tab', 'Site');
      print $html->hidden_text('searchSite', $searchSite);
      print $html->endform();
   }

   $section = 'section' . $siteSection;

   call_user_func($section);
}

function sectionSecurityViolation()
{
   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $db = $global['db'];
   $location_code = $global['searchSite'];

   $sql_req = "SELECT name, ipaddress, security_violations, details FROM lan_switch WHERE name LIKE '%$location_code%' AND service LIKE '%Managed%' AND enabled = 1";
   $sql_res = $db->query($sql_req);

   foreach ($sql_res as $sw_name => $props) {
      $details = json_decode($props['details'], true);
      $sec_vio = json_decode($props['security_violations'], true);

      foreach ($sec_vio as $if => $val) {
         foreach ($details['interfaces'] as $key => $value) {
            if ($if == $value['ifname']) {
               preg_match('/(?<= address \()(.*)(?=\) is )/i', $val['msg_txt'], $match);

               $endpoint = $match[0];

               $endpoints[] = [
                  'endpoint' => $endpoint,
                  'switch' => $sw_name,
                  'switch_ip' => $props['ipaddress'],
                  'interface' => $if,
                  'port_description' => $value['ifdesc'],
                  'last_seen' => $val['last_seen'],
                  'syslog_msg' => $val['msg_txt']
               ];
            }
         }
      }
   }

   $columns = [
      'Endpoint' => 'endpoint',
      'Switch' => 'switch',
      'Switch IP' => 'switch_ip',
      'Interface' => 'interface',
      'Port Description' => 'port_description',
      'Last Seen' => 'last_seen',
      'Syslog Message' => 'syslog_msg'
   ];

   $opts = ['datatable' => 1, 'table.id' => 'endpoint_sec_vio'];

   $sec_vio_url = "<a target='_blank' href='https://emerson.sharepoint.com/sites/OneEmerson-EDIS-LAN/SitePages/Frequently-Asked-Questions-(FAQ).aspx#anchor'>click here</a>";

   $msgs[] = "Security violation means there are ports that have multiple mac addresses reporting on the same physical port that could indicate an unmanage switch or any device such as VMWare, some security cameras that has multiple IPs and mac addresses. To learn more about this feature, $sec_vio_url.";

   echo $html->info_message($msgs, "Security Violation Guide");

   echo $html->table($endpoints, $columns, $opts);
}

function count_security_violations($location_code)
{
   global $global;

   $db = $global['db'];

   $sql_req = "SELECT name, security_violations, details FROM lan_switch WHERE name LIKE '%$location_code%' AND service LIKE '%Managed%' AND enabled = 1";
   $sql_res = $db->query($sql_req);
   $sec_vios = 0;

   foreach ($sql_res as $sw_name => $props) {
      $details = json_decode($props['details'], true);
      $sec_vio = json_decode($props['security_violations'], true);

      foreach ($sec_vio as $if => $val) {
         foreach ($details['interfaces'] as $key => $value) {
            if ($if == $value['ifname']) {
               $sec_vios++;
            }
         }
      }
   }

   return $sec_vios;
}

function showSwitch()
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $config = $global['config'];
   $snmp = $global['snmp'];
   $func = $global['func'];
   $tool_name = $global['tool_name'];
   $userinfo = $global['userinfo'];

   $oids = $global['oids'];
   $portModes = $global['portModes'];

   $modal = $global['modal'];
   $submit = $global['submit'];
   $connect = $global['connect'];

   $switches = $global['switches'];
   $selectSwitch = $global['selectSwitch'];
   $searchSwitch = $global['searchSwitch'];

   $permission = $global['permission'];

   $selectIfConfig = $global['selectIfConfig'];

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print "<div class='ui two column grid'>";
   print "<div class='column'>";
   print $html->select(
      'searchSwitch',
      $selectSwitch,
      $searchSwitch,
      array('class' => 'ui fluid search selection dropdown')
   );
   print "</div>";
   print "<div class='column'>";
   print $html->hidden_text('tab', 'Switch');
   print $html->submit('connect', 'Connect', array('color' => 'gray'));
   print "</div>";
   print "</div>";
   print $html->endform();

   if ($searchSwitch) {
      $sql_req = "SELECT id, security_violations FROM lan_switch WHERE name = '$searchSwitch' AND service LIKE '%Managed%' AND enabled = 1";
      $sql_res = $db->query($sql_req);


      foreach ($sql_res as $index => $value) {
         $sec_vio = json_decode($value['security_violations'], true);
      }

      print "<br>";

      $func->loadstart('Loading, please wait ...', 'showSwitch');

      $query = "SELECT JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) as object, " .
         "tool_log_date as updated, tool_log_user as updated_by, " .
         "JSON_UNQUOTE(json_extract(tool_log_desc,'$.data')) as data, " .
         "JSON_UNQUOTE(json_extract(tool_log_desc,'$.action')) as action, " .
         "JSON_UNQUOTE(json_extract(tool_log_desc,'$.status')) as status " .
         "FROM tool_changelog WHERE tool_log_tool like 'lan.manager' " .
         " AND tool_log_desc like '%$searchSwitch%'";

      $swLogs = $db->query($query);

      // check if the device responds to SNMP  ?
      $system = $snmp->walk($switches[$searchSwitch]['ipaddress'], $oids['sysdescr']);

      if (empty($system)) {
         $message = "Could not get current switch config via SNMP... Contact Service Desk for more details.";
         print $html->error_message($message, 'ERROR');
         $func->loadfinish('showSwitch');
      } else {
         if ($_SESSION['pagecache'][$searchSwitch] && !preg_match('/Configure|Add Port Channel|Add VLAN|Copy Port Config/i', $submit) && !$connect) {
            $interfaces = $_SESSION['pagecache'][$searchSwitch]['interfaces'];
            $vlans = $_SESSION['pagecache'][$searchSwitch]['vlans'];
         } else {
            $interfaces = collectInterfaceData($searchSwitch);
            $vlans = collectVlanData($searchSwitch);
            $_SESSION['pagecache'][$searchSwitch]['interfaces'] = $interfaces;
            $_SESSION['pagecache'][$searchSwitch]['vlans'] = $vlans;
         }

         foreach ($interfaces as $id => $info) {
            if (!preg_match('/Vlan|Null|VLAN|Stack/', $info['ifdescr'])) {

               if (!preg_match('/[[:print:]]/', $info['ifcdpid'])) {
                  $info['ifcdpid'] = null;
               }

               $cdp_neighbor = "";

               if ($info['ifcdpid'] && $info['ifcdpdevice']) {
                  $cdp_neighbor = $info['ifcdpid'] . "<br>(" . $info['ifcdpdevice'] . ")";
               }

               if (!$info['ifcdpid'] && $info['ifcdpdevice']) {
                  $cdp_neighbor = "Unkown ID<br>(" . $info['ifcdpdevice'] . ")";
               }

               if (!$info['ifcdpid'] && !$info['ifcdpdevice']) {
                  $cdp_neighbor = "";
               }

               $interface_name = abbreviate($info['ifdescr']);

               // We will allow config of trunk ports that are detected as Flexconnnect
               $allowedModes = [1, 3, 4, 5];

               if (in_array($info['ifmode'], $allowedModes)) {
                  $isFlexconnect = checkIfFlexConnect($info, $vlans);
               }

               $interfaces[$id]['switchInterface'] = hash('sha256', $info['ifdescr'] . $tool_name);
               $currentIfConfig64 = base64_encode(json_encode($interfaces[$id], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));

               // if (
               //    $info["ifmode"] == 2 &&
               //    !preg_match("/FCAP/i", $info["ifalias"]) &&
               //    !preg_match("/LMAP/i", $info["ifalias"]) &&
               //    !preg_match("/L@CK/i", $info["ifalias"])
               // ) 

               $interface_info[] = array(
                  'Interface' => "<a href='/lan-manager.php?tab=Switch&" .
                     "searchSwitch=$searchSwitch&submit=RunInterfaceConfig&switchInterface=" . $info['ifdescr'] .
                     "' data-toggle='tooltip' title='Click to see interface running config'>" .
                     $info['ifdescr'] . "</a>",
                  'Operational Status' => ($info['ifoperstatus'] == 1) ? '<a class="ui green empty circular label" data-toggle="tooltip" title="Connected"></a>' :
                     (($info['iferrdisabled']) ? '<a class="ui orange empty circular label" data-toggle="tooltip" title="Error Disable"></a>' :
                        '<a class="ui red empty circular label" data-toggle="tooltip" title="Not Connected"></a>'),
                  'Admin Status' => ($info['ifadminstatus'] == 1) ? '<a class="ui green empty circular label" data-toggle="tooltip" title="Up"></a>' :
                     '<a class="ui red empty circular label" data-toggle="tooltip" title="Shut Down"></a>',
                  'Mode' => $portModes[$info['ifmode']],
                  'Duplex' => ($info['ifoperstatus'] == 1) ? $selectIfConfig['duplex'][$info['ifduplex']] : '',
                  'Speed (Mbps)' => ($info['ifoperstatus'] == 1) ? round($info['ifspeed'] / 1000000) : '',
                  'Vlan' => ($info['ifvlan'] && $info['ifmode'] == 2) ? $info['ifvlan'] :
                     (($info['iftrunkvlan'] && $info['ifmode'] != 2) ?
                        implode(', ', $info['iftrunkvlan']) : ''),
                  'Voice Vlan' => ($info['ifvoicevlan'] == '4096') ? '' : $info['ifvoicevlan'],
                  'Description' => $info['ifalias'],
                  'Configure' => (($info['ifmode'] == 2 || $isFlexconnect) &&
                     (preg_match('/basic|full/i', $permission) && !preg_match('/port-channel/i', $info['ifdescr']) &&
                        !preg_match('/SVSL|SVSS|L@CK|PCME|port-channel/i', $info['ifalias']))) ?
                     "<a href='/lan-manager.php?tab=Switch&" .
                     "searchSwitch=$searchSwitch&modal=ConfigureInterface&switchInterface=" . $info['ifdescr'] .
                     "&currentIfConfig=$currentIfConfig64" .
                     "'>Configure " . $info['ifdescr'] . "</a>" :
                     ((preg_match('/port-channel/i', $info['ifdescr']) && preg_match('/full/i', $permission)) ?
                        "<a href='/lan-manager.php?tab=Switch&" .
                        "searchSwitch=$searchSwitch&modal=ConfigurePortChannel&switchInterface=" . $info['ifdescr'] .
                        "&currentIfConfig=$currentIfConfig64" .
                        "'>Configure " . $info['ifdescr'] . "</a>" :
                        ((preg_match('/env/i', $permission) && $info['ifmode'] == 2 && !preg_match('/LMAP|FCAP/i', $info['ifalias'])) ?
                           "<a href='/lan-manager.php?tab=Switch&" .
                           "searchSwitch=$searchSwitch&modal=ConfigureInterface&switchInterface=" . $info['ifdescr'] .
                           "&currentIfConfig=$currentIfConfig64" .
                           "'>Configure " . $info['ifdescr'] . "</a>" : "")),
                  'CDP Neighbor' => $cdp_neighbor,
                  'Last configured' => $swLogs ? $swLogs[$searchSwitch . '/' . $info['ifdescr']]['updated_by'] . '<br>' .
                     $swLogs[$searchSwitch . '/' . $info['ifdescr']]['updated'] : 'No info',
                  'Security Violation' => $sec_vio[$info['ifdescr']]['last_seen']
               );
            }
         }

         $func->loadfinish('showSwitch');

         if ($interfaces) {
            $query = "SELECT name, ipaddress, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation" .
               " FROM lan_switch WHERE name LIKE '$searchSwitch'";
            $switchDetails = $db->query($query);

            $sysLocation = $switchDetails[$searchSwitch]['sysLocation'];

            print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

            print $html->hidden_text('tab', 'Switch');
            print $html->hidden_text('searchSwitch', $searchSwitch);

            print $html->submit('submit', 'Run CLI Commands', array('size' => 'tiny', 'color' => 'teal'));

            if (!preg_match('/env/', $permission)) {
               print $html->submit('submit', 'Update ServiceNow POC', array('size' => 'tiny', 'color' => 'blue'));
            }

            if (preg_match('/full/i', $permission)) {
               print $html->submit('submit', 'Add VLAN(s)', array('size' => 'tiny', 'color' => 'yellow'));
               print $html->submit('submit', 'Add Port Channel', array('size' => 'tiny', 'color' => 'orange'));
            }

            if (preg_match('/full|basic/i', $permission)) {
               print $html->submit("submit", "Copy Port Config", ["size" => "tiny", "color" => "blue"]);
            }

            if (preg_match('/fulladmin/i', $permission)) {
               print $html->submit('submit', 'Update Banner', array('size' => 'tiny', 'color' => 'blue'));
            }

            print $html->hidden_text('switch_hostname', $func->encrypt_var($tool_name, $searchSwitch, 1));
            print $html->hidden_text('user_name', $func->encrypt_var($tool_name, $userinfo['username'], 1));
            print $html->hidden_text('tool_name', $tool_name);

            print $html->endform();
            print "<br>";
            print "<div>";
            print $html->divbox('sysLocation', "SysLocation: $sysLocation");
            print "<div style='float: right; margin-top: 20px;'>";
            print $html->submit('reset_security_violations_button', 'Reset Security Violation(s)', array('color' => 'red'));
            print "</div>";
            print "</div>";

            print "<div id='reset_security_violations_modal' style='display: none'>
                  <div class='ui icon header'>
                     <i class='remove icon'></i>
                     Remove Security Violations
                  </div>
                  <div>
                     <div class='content' style='margin: 10px 5px;'>
                        <p style='font-weight: bold;' >Are you sure you want to reset the security violations for $searchSwitch? This action is irreversible.</p>
                     </div >
                     <div class='actions' style='margin-top: 10px; display: flex; align-items: center; justify-content: center;'>
                        <div>
                           <div class='ui red basic cancel inverted button'>
                              <i class='remove icon'></i>
                              No
                           </div>
                           <div class='ui red ok inverted button' id='yes_reset_security_violations_button'>
                              <i class='checkmark icon'></i>
                              Yes
                           </div>
                        </div>
                     </div>
                  </div>
                  </div>";

            print "<div id='reset_security_violations_status'></div>";

            print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

            print $html->table($interface_info, array(), array('table.style' => 'font-size:0.8em;'));

            print $html->endform();
         } else {
            print $html->warning_message("No interface information. Please contact Service Desk ");
         }
      }
   }

   if ($submit == "Copy Port Config") {
      show_copy_port_config_modal_void($interfaces, $vlans);
   }

   if (preg_match('/ConfigureInterface/i', $modal) && !preg_match('/cancel/i', $submit)) {
      showConfigureInterfaceModal($interfaces, $vlans);
   }

   if (preg_match('/Add VLAN/i', $submit) && !preg_match('/cancel/i', $submit)) {
      showAddVLANsModal();
   }

   if (preg_match('/Add Port Channel/i', $submit) && !preg_match('/cancel/i', $submit)) {
      showAddPortChannelModal($interfaces, $vlans);
   }

   if (preg_match('/Update Banner$/i', $submit) && !preg_match('/cancel/i', $submit)) {
      showUpdateBannerModal();
   }

   if (preg_match('/ConfigurePortChannel/i', $modal) && !preg_match('/cancel/i', $submit)) {
      showConfigurePortChannelModal($interfaces, $vlans);
   }

   if (preg_match('/RunInterfaceConfig/i', $submit)) {
      showRunInterfaceConfigModal();
   }

   if (preg_match('/run cli commands/i', $submit)) {
      showRunCLICommandModal($interfaces, $vlans);
   }

   if (preg_match('/update servicenow poc/i', $submit)) {
      showUpdateServiceNowModal();
   }
}

function collectSubnetData($searchSwitch, $gridMaster = null)
{
   //This function will send a request to obtain all the subnets and their respective vlans based on a given location
   global $global;

   $config = $global['config'];

   preg_match('/L[0-9]+/', $searchSwitch, $match);
   $location = $match[0];

   if (!$gridMaster) {
      $gridMaster = new GridMaster();
      $auth = $gridMaster->authenticate($config['gridmaster.host'], $config['tacacs.user'], $config['tacacs.pass']);
   }

   $filter = array('_return_fields%2B' => 'network,members,extattrs', '*LocationID' => $location);
   $result = $gridMaster->getNetwork($filter);

   $data = array();

   foreach ($result as $key => $value) {
      $data[] = [
         'network' => $value['network'],
         'vlan_id' => $value['extattrs']['VLAN_ID_Emr']['value'],
         'vlan_name' => $value['extattrs']['VLAN_Name_Emr']['value']
      ];
   }

   return $data;
}

function getPortChannelMembers($ip)
{
   global $global;

   $ssh = $global['ssh'];
   $config = $global['config'];
   $func = $global['func'];

   $result = '';

   if ($func->ping($ip))
      $conn = $ssh->connect($ip, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);

   if ($conn) {
      $ssh->command("terminal length 0");
      $result = $ssh->command("show etherchannel summary", 5);
      $splitted = preg_split("/Group[' ']+Port-channel[' ']+Protocol[' ']+Ports/i", $result);
      preg_match_all("/Gi\d+[\/\d+]*|Te\d+[\/\d+]*|Fo\d+[\/\d+]*/i", $splitted[1], $match);

      $interfaces = array();

      foreach ($match[0] as $key => $value) {
         if (preg_match('/Gi/i', $value))
            $interfaces[] = str_replace('Gi', '', $value);
         if (preg_match('/Te/i', $value))
            $interfaces[] = str_replace('Te', '', $value);
         if (preg_match('/Fo/i', $value))
            $interfaces[] = str_replace('Fo', '', $value);
      }

      $result = implode(' ', $interfaces);

      $ssh->disconnect();
   }

   return $result;
}

function show_copy_port_config_modal_void($_interface_arr, $_vlan_arr)
{
   global $global;

   $func = $global["func"];
   $html = $global["html"];

   $htmx_page_url_str = $global["htmx_page_url_str"];
   $switch_hostname_str = $global["searchSwitch"];

   $src_interface_arr = [];
   $dest_interface_arr = [];

   foreach ($_interface_arr as $key => $value_arr) {

      // Skip ports that aren't in access mode.
      if ($value_arr["ifmode"] != 2) {
         continue;
      }

      if (preg_match('/Vlan|Null|Stack|Bluetooth|(^GigabitEthernet0\/0$)|Port/i', $value_arr["ifdescr"])) {
         continue;
      }

      $alias_str = $value_arr["ifalias"];

      if (preg_match("/FCAP|LMAP|L@CK/i", $alias_str)) {
         continue;
      }

      $src_interface_arr[] = $value_arr["ifdescr"];
      $dest_interface_arr[] = $value_arr["ifdescr"];
   }

   echo "<div id='port_over_config_modal' class='ui modal'>"; {
      echo "<i class='close icon'></i>";

      echo "<div class='header'>{$switch_hostname_str} - Copy Port Config</div>";

      echo "<div class='content'>"; {
         if (empty($src_interface_arr) || empty($dest_interface_arr)) {
            echo $html->error_message("No interfaces found for port over configuration.", "Error");
         } else {
            $src_select_options_arr = [
               "selected_value" => $src_interface_arr,
               "keys_as_values" => false,
               "do_initialize" => true
            ];

            $dest_select_options_arr = [
               "selected_value" => $dest_interface_arr,
               "keys_as_values" => false,
               "do_initialize" => true
            ];

            $generate_port_over_config_button_props_arr = [
               "color" => "gray",
               "disable" => true
            ];
            echo $html->info_message("We're only allowing copy port configs for ports that are in access mode, have no aps and aren't locked out.", "Info");

            echo "<div class='ui two column grid'>"; {
               echo "<div id='port_config_over_modal_segment_one' class='column'>"; {
                  $form_props_arr = [
                     "id" => "generate_port_over_config_form",
                     "method" => "post",
                     "hx-post" => $htmx_page_url_str,
                     "hx-target" => "#port_over_config_modal_segment_two",
                     "hx-swap" => "innerHTML",
                  ];

                  $form_data_arr = [
                     "func_name" => "render_generated_copy_port_config_void",
                     "switch_hostname" => $switch_hostname_str,
                  ];

                  echo $html->startform($form_props_arr); {
                     echo "<div style='display: flex; flex-direction: column; gap: 5px;'>"; {

                        echo $html->semantic_ui_dropdown("select_src_interface", "Select Source Interface", $src_interface_arr, $src_select_options_arr);
                        echo $html->semantic_ui_dropdown("select_dest_interface", "Select Destination Interface", $dest_interface_arr, $dest_select_options_arr);

                        echo $html->submit(
                           "generate_port_over_config_button",
                           "Generate Copy Port Config",
                           $generate_port_over_config_button_props_arr
                        );

                        echo $html->form_data($form_data_arr);
                     }
                     echo "</div>";
                  }
                  echo $html->endform();
               }
               echo "</div>";

               echo "<div id='port_over_config_modal_segment_two' class='column'>"; {
                  echo "<div style='display: flex; flex-direction: column; gap: 5px;'>"; {
                     echo "<textarea id='port_over_config_text_area' name='port_over_config_text_area' " .
                        "placeholder='Configuration from the source interface will be pre-generated here. Feel free to modify the configuration of the destination port, if your heart desires so.' " .
                        "style='width: 100%; height: 300px;'></textarea>";

                     echo $html->submit(
                        "apply_copy_port_config_button",
                        "Apply Copy Port Config",
                        [
                           "color" => "gray",
                           "disable" => true,
                        ]
                     );
                  }
                  echo "</div>";
               }
               echo "</div>";

            }
            echo "</div>";

            echo $html->loading_message(
               "load_message_port_over_config_modal",
               "Just a second",
               "Please, wait while we process your request.",
               ["style" => "margin-top: 10px; display: none;"]
            );
         }
      }
      echo "</div>";
   }
   echo "</div>";
}

function render_generated_copy_port_config_void()
{
   global $global;

   $db = $global["db"];
   $func = $global["func"];
   $html = $global["html"];
   $config = $global["config"];

   $switch_hostname_str = $_POST["switch_hostname"];
   $switch_ip_address_str = "";
   $src_interface_str = $_POST["select_src_interface"];
   $dest_interface_str = $_POST["select_dest_interface"];

   $db_req_str = "SELECT name, ipaddress FROM lan_switch WHERE enabled = 1 AND deleted = 0";
   $db_res_arr = $db->query($db_req_str);

   foreach ($db_res_arr as $value_arr) {
      if ($value_arr["name"] == $switch_hostname_str) {
         $switch_ip_address_str = $value_arr["ipaddress"];
         break;
      }
   }

   $ssh = new SSH();
   $ssh_conn_bool = $ssh->connect($switch_ip_address_str, "22", $config["lanmgmt.user"], $config["lanmgmt.pass"]);

   $generate_config_arr = [];

   if ($ssh_conn_bool === true) {
      $ssh->command("terminal length 0");

      $result_str = $ssh->command("show run int $src_interface_str", 10);

      $frag_arr = explode("\n", $result_str);
      array_pop($frag_arr);

      foreach ($frag_arr as $key_int => $value_str) {
         if (preg_match('/^interface/i', $value_str)) {
            $value_str = str_replace($src_interface_str, $dest_interface_str, $value_str);

            for ($i = 0; $i < $key_int; $i++) {
               unset($frag_arr[$i]);
            }
         }

         if (preg_match('/description/i', $value_str)) {
            unset($frag_arr[$key_int]);
            continue;
         }

         $frag_arr[$key_int] = trim($value_str);
      }

      foreach ($frag_arr as $key_int => $value_str) {
         $generate_config_arr[] = $value_str;
      }

      $ssh->disconnect();
   } else {
      echo $html->error_message("Could not SSH to $switch_hostname_str", "Error");
      return;
   }

   $form_props_arr = [
      "method" => "post",
      "action" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)
   ];

   $form_data_arr = [
      "tab" => "Switch",
      "searchSwitch" => $switch_hostname_str,
      "src_interface" => $src_interface_str,
      "dest_interface" => $dest_interface_str,
      "config" => json_encode($generate_config_arr),
   ];

   $item_arr = [
      "Source Interface" => $src_interface_str,
      "Destination Interface" => $dest_interface_str,
   ];

   echo "<div style='margin-bottom: 5px;'>"; {
      echo $html->drawer($item_arr);
   }
   echo "</div>";

   echo $html->startform($form_props_arr); {
      echo "<div style='display: flex; flex-direction: column; gap: 5px;'>"; {
         echo "<textarea id='port_over_config_text_area' disabled name='port_over_config_text_area' style='width: 100%; height: 300px;'>"; {
            foreach ($generate_config_arr as $value_str) {
               echo $value_str . "\n";
            }
         }
         echo "</textarea>";

         echo $html->submit(
            "submit",
            "Apply Copy Port Config",
            [
               "color" => "green",
            ]
         );

         echo $html->form_data($form_data_arr);
      }
      echo "</div>";
   }
   echo $html->endform();
}

function apply_copy_port_config_void()
{
   global $global;

   if (!$_GET["htmx"]) {
      return;
   }

   $db = $global["db"];
   $func = $global["func"];
   $html = $global["html"];
   $config = $global["config"];

   $src_interface_str = $_POST["src_interface"];
   $dest_interface_str = $_POST["dest_interface"];

   $switch_hostname_str = $global["searchSwitch"];
   $switch_ip_address_str = "";

   $copy_port_config_arr = json_decode($_POST["config"], true);
   $copy_port_config_str = implode("\n", $copy_port_config_arr);

   array_pop($copy_port_config_arr);
   array_push($copy_port_config_arr, "exit");

   $func->loadstart("Applying copy port config to $switch_hostname_str", $switch_hostname_str);

   $config_applied_bool = true;
   $cmd_history_arr = [];

   $db_req_str = "SELECT name, ipaddress FROM lan_switch WHERE enabled = 1 AND deleted = 0";
   $db_res_arr = $db->query($db_req_str);

   foreach ($db_res_arr as $value_arr) {
      if ($value_arr["name"] == $switch_hostname_str) {
         $switch_ip_address_str = $value_arr["ipaddress"];
         break;
      }
   }

   if (!$switch_ip_address_str) {
      echo $html->error_message("Could not find IP address for $switch_hostname_str", "Error");
      return;
   }

   $ssh = new SSH();
   $ssh_conn_bool = $ssh->connect($switch_ip_address_str, "22", $config["lanmgmt.user"], $config["lanmgmt.pass"]);

   $old_dest_interface_config_arr = [];

   if ($ssh_conn_bool === true) {
      $ssh->command("terminal length 0");
      $cmd_history_arr[] = "terminal length 0";

      $result_str = $ssh->command("show run int $dest_interface_str", 10);
      $old_dest_interface_config_arr = explode("\n", $result_str);

      $ssh->command("conf t");
      $cmd_history_arr[] = "conf t";

      foreach ($copy_port_config_arr as $value_str) {
         $output_str = $ssh->command($value_str);
         $cmd_history_arr[] = $value_str;

         if (preg_match("/invalid/i", $output_str)) {
            $config_applied_bool = false;
            break;
         }
      }

      if ($config_applied_bool === true) {
         $ssh->command("exit");
         $cmd_history_arr[] = "exit";

         // Save changes
         $ssh->command("copy running-config startup-config", 10);
         $cmd_history_arr[] = "copy running-config startup-config";
      }

      $ssh->disconnect();
   } else {
      $func->loadfinish($switch_hostname_str);
      echo $html->error_message("Could not SSH to $switch_hostname_str", "Error");
      return;
   }

   $func->loadfinish($switch_hostname_str);

   $error_msg_str = "";
   $success_msg_str = "";

   if ($config_applied_bool === false) {
      $msg_str = "Could not apply copy port config to $switch_hostname_str";
      echo $html->error_message($msg_str, "Error");

      $error_msg_str = $msg_str;
   } else {
      $msg_str = "Copy Port config applied successfully to $switch_hostname_str";
      echo $html->success_message($msg_str, "Success");

      $success_msg_str = $msg_str;
   }

   $log_data_arr = [
      "action" => "Copy Port Config",
      "object" => $switch_hostname_str,
      "data" => [
         "src_interface" => $src_interface_str,
         "dest_interface" => $dest_interface_str,
         "old_dest_interface_config" => $old_dest_interface_config_arr,
         "cmd_history" => $cmd_history_arr,
      ],
      "status" => [
         "success" => $success_msg_str,
         "error" => $error_msg_str,
      ]
   ];

   $log_data_str = json_encode($log_data_arr);

   $func->logchange($db, $log_data_str);
}

function showAddPortChannelModal($interfaces, $vlans)
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $config = $global['config'];
   $tab = $global['tab'];

   $searchSwitch = $global['searchSwitch'];

   $statement = "SELECT name, ipaddress FROM lan_switch WHERE name = '$searchSwitch'";
   $queryResult = $db->query($statement);
   $ip = $queryResult[$searchSwitch]['ipaddress'];

   $usedPortChannels = getPortChannelMembers($ip);

   $portChannels = array();
   $selectInterfaces[] = 'Select Interface';

   foreach ($interfaces as $key => $value) {
      if (!preg_match('/Gigabit|Port-channel/i', $value['ifdescr']) || preg_match('/App/i', $value['ifdescr']))
         continue;

      if (preg_match('/Gigabit/i', $value['ifdescr'])) {
         $split = preg_split('/Ethernet/i', $value['ifdescr']);
         $split[1] = str_replace('/', '\/', $split[1]);

         if (preg_match('/' . $split[1] . '/', $usedPortChannels))
            continue;

         $selectInterfaces[] = $value['ifdescr'];
      } else
         $portChannels[] = $value['ifdescr'];
   }

   $portChannels = json_encode($portChannels);

   print $html->startmodal("Add Port Channel for $searchSwitch", true);
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print "<td>" . $html->input_text("portchannel_id", $intID, 2, 8, array('placeholder' => 'POCH ID')) . "</td>";
   print "<td>" . $html->input_text("portchannel_desc", $intID, 30, 40, array('style' => 'margin-left:120px;', 'placeholder' => 'Port Channel Description')) . "</td>";
   print "<br><br>";
   print $html->select('interface1', $selectInterfaces);
   print "<td>" . $html->input_text("interface1_desc", $intID, 30, 40, array('style' => 'margin-left:10px;', 'placeholder' => 'Interface Description')) . "</td>";
   print "<br><br>";
   print $html->select('interface2', $selectInterfaces);
   print "<td>" . $html->input_text("interface2_desc", $intID, 30, 40, array('style' => 'margin-left:10px;', 'placeholder' => 'Interface Description')) . "</td>";
   print "<br><br>";
   print "<br><br>";
   print "Select VLANs to allow on Port Channel";
   print '<select multiple="multiple" class="ui fluid search dropdown" id="portchannel_vlans" multiple="" name="portchannel_vlans[]" autocomplete="on">';

   foreach ($vlans as $id => $value) {
      print "<option>$value</option>";
   }
   print "</select>";

   print $html->hidden_text('json_allPortChannels', $portChannels);
   print $html->endmodalcontent();
   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('tab', $tab);
   print $html->hidden_text('siteSection', $siteSection);
   print $html->hidden_text('searchSwitch_ip', $ip);
   print $html->submit('submit', 'Cancel', array('size' => 'large', 'color' => 'red', 'align' => 'right floated'));
   print $html->submit('submit', 'Create Port Channel', array('size' => 'large', 'color' => 'green', 'align' => 'right floated'));
   print "<br><br><br><br>";
   print $html->endform();
   print $html->endmodal();
}

function createPortChannel()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $userinfo = $global['userinfo'];
   $html = $global['html'];
   $config = $global['config'];

   $searchSwitch = $global['searchSwitch'];

   // $vlan_id = intval(filter_var($input->get("vlanID_$i", 'all'), FILTER_SANITIZE_STRING));
   // $vlan_name = filter_var($input->get("vlanName_$i", 'all'), FILTER_SANITIZE_STRING);   

   $ip = filter_var($input->get('searchSwitch_ip', 'all'), FILTER_SANITIZE_STRING);

   $func->loadstart("Adding Port Channel to $searchSwitch", $ip);

   $userData = [
      'portChannel' => [
         'id' => filter_var($input->get('portchannel_id', 'all'), FILTER_SANITIZE_STRING),
         'desc' => filter_var($input->get('portchannel_desc', 'all'), FILTER_SANITIZE_STRING)
      ],
      'interface1' => [
         'value' => filter_var($input->get('interface1', 'all'), FILTER_SANITIZE_STRING),
         'desc' => filter_var($input->get('interface1_desc', 'all'), FILTER_SANITIZE_STRING)
      ],
      'interface2' => [
         'value' => filter_var($input->get('interface2', 'all'), FILTER_SANITIZE_STRING),
         'desc' => filter_var($input->get('interface2_desc', 'all'), FILTER_SANITIZE_STRING)
      ],
      'selectedVLANs' => [
         'values' => $input->get('portchannel_vlans', 'all')
      ]
   ];

   $success = $error = array();

   $portChannels = json_decode($input->get('json_allPortChannels', 'all'), true);
   $matches = 0;

   foreach ($portChannels as $index => $poch) {
      $poch_id = str_replace("Port-channel", "", $poch);

      if ($poch_id == $userData['portChannel']['id'])
         $matches++;
   }

   $error_flag = 0;
   $error_msgs = [];

   if (preg_match('/swa/i', $searchSwitch)) {
      if (!empty($matches) || ($userData['portChannel']['id'] > 2 && $userData['portChannel']['id'] < 20) || $userData['portChannel']['id'] > 80) {
         $error_flag++;
         $error_msgs[] = "Accesses Switches can only had POCH ID(s) that are not between 2 and 20 or bigger than 80";
         //return 0;
      }
   } else if (!empty($matches) || $userData['portChannel']['id'] < 20 || $userData['portChannel']['id'] > 80) {
      $error_flag++;
      $error_msgs[] = "You can only have POCH ID(s) between 20 and 80";
      //return 0;
   }

   if ($userData['portChannel']['desc'] == 'POCH|MON' || empty($userData['portChannel']['desc']) || !preg_match('/POCH|MON/i', $userData['portChannel']['desc'])) {
      $error_flag++;
      $error_msgs[] = "The POCH description needs to contain 'POCH|MON|<your description>' and cannot be null";
      //return 0;
   }

   if ($userData['interface1']['value'] == $userData['interface2']['value'] || empty($userData['interface1']['value']) || empty($userData['interface2']['value'])) {
      $error_flag++;
      $error_msgs[] = "Selected interfaces must be different and cannot have null values";
      //return 0;
   }

   if ($userData['interface1']['desc'] == 'TRKN|MON' || empty($userData['interface1']['desc']) || !preg_match('/TRNK|MON/i', $userData['interface1']['desc'])) {
      $error_flag++;
      $error_msgs[] = "The First Interface description needs to contain 'TRNK|MON|<your description>' and cannot be null";
      //return 0;
   }

   if ($userData['interface2']['desc'] == 'TRKN|MON' || empty($userData['interface2']['desc']) || !preg_match('/TRNK|MON/i', $userData['interface2']['desc'])) {
      $error_flag++;
      $error_msgs[] = "The Second Interface description needs to contain 'TRNK|MON|<your description>' and cannot be null";
      return 0;
   }

   if (empty($userData['selectedVLANs']['values'])) {
      $error_flag++;
      $error_msgs[] = "Seems like no VLANS were found";
   }

   if ($error_flag) {
      $func->loadfinish($ip);
      echo $html->error_message($error_msgs, "POCH Creation Failure. Why?");
      return 0;
   }

   if ($func->ping($ip))
      $conn = $ssh->connect($ip, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);

   if ($conn) {
      $result[] = $ssh->command("conf t", 1);
      $result[] = $ssh->command("file prompt quiet");

      $vlansID = array();

      foreach ($userData['selectedVLANs']['values'] as $key => $value) {
         preg_match("/\d+[' ']/", $value, $match);

         $vlansID[] = trim($match[0]);
      }
      $vlans = implode(',', $vlansID);

      $ssh->command("default interface " . $userData['interface1']['value'], 1);
      $ssh->command("interface " . $userData['interface1']['value'], 1);
      $ssh->command("desc " . $userData['interface1']['desc'], 1);
      $ssh->command("channel-group " . $userData['portChannel']['id'] . " mode on", 1);
      $ssh->command("exit", 1);

      $ssh->command("default interface " . $userData['interface2']['value'], 1);
      $ssh->command("interface " . $userData['interface2']['value'], 1);
      $ssh->command("desc " . $userData['interface2']['desc'], 1);
      $ssh->command("channel-group " . $userData['portChannel']['id'] . " mode on", 1);
      $ssh->command("exit", 1);

      $ssh->command("interface port-channel " . $userData['portChannel']['id'], 1);
      $ssh->command("ip dhcp snooping trust", 1);
      $ssh->command("desc " . $userData['portChannel']['desc']);
      $ssh->command("switchport trunk allowed vlan $vlans", 5);
      $ssh->command("switchport access vlan 2", 1);
      $ssh->command("switchport trunk native vlan 2", 1);
      $ssh->command("switchport mode trunk", 1);
      $ssh->command("switchport nonegotiate", 1);
      $ssh->command("no ip address");
      $ssh->command("no ip device tracking max");
      $ssh->command("exit");

      $ssh->command("end");
      $ssh->command("copy running-config startup-config");
      $ssh->command("conf t");
      $ssh->command("file prompt alert");
      $ssh->command("end");

      $ssh->disconnect();
      $func->loadfinish($ip);

      $success[] = "Port Channel " . $userData['portChannel']['id'] . " has been created";
      $success[] = "Members: " . $userData['interface1']['value'] . ', ' . $userData['interface2']['value'];
      $success[] = "VLAN(s): $vlans";

      print $html->success_message($success, 'SUCCESS');
   } else {
      $func->loadfinish($ip);
      $error[] = "Could not connect to $searchSwitch - $ip";
      print $html->error_message($error, 'ERROR');
   }

   $logs = [
      "POCH " . $userData['portChannel']['id'] => [
         "interface1" => $userData['interface1']['value'],
         "interface2" => $userData['interface2']['value'],
         "vlans" => $vlans
      ]
   ];

   $logData = array(
      'action' => 'Create PortChannel',
      'object' => $searchSwitch,
      'data' => $logs,
      'status' => array('success' => $success, 'error' => $error),
   );

   $func->logchange($db, json_encode($logData));
   $func->loguser($db, $searchSwitch, 'create.portchannel');
}

function showAddVLANsModal()
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $tab = $global['tab'];
   $config = $global['config'];

   $searchSwitch = $global['searchSwitch'];

   $vlans = json_encode(collectVlanData($searchSwitch));

   print $html->startmodal("Add VLAN(s) for $searchSwitch", true);
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->hidden_text('json_VLANData', $vlans);


   if (preg_match('/swc/i', $searchSwitch)) {
      $subnets = json_encode(collectSubnetData($searchSwitch));

      print $html->hidden_text('json_SubnetData', $subnets);

      vlanLayer3Switch();
   } else {
      vlanLayer2Switch();
   }

   print $html->endmodalcontent();
   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('tab', $tab);
   print $html->hidden_text('siteSection', $siteSection);
   print "<br><br>";
   print $html->submit('submit', 'Cancel', array('size' => 'small', 'color' => 'red', 'align' => 'right floated'));
   print $html->submit('submit', 'Create VLAN(s)', array('size' => 'small', 'color' => 'green', 'align' => 'right floated'));
   print "<br><br><br><br>";
   print $html->endform();
   print $html->endmodal();
}

function createVLANs()
{
   global $global;

   $db = $global['db'];
   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $html = $global['html'];
   $func = $global['func'];
   $config = $global['config'];

   $gridMaster = new GridMaster();
   $auth = $gridMaster->authenticate($config['gridmaster.host'], $config['tacacs.user'], $config['tacacs.pass']);

   $userinfo = $global['userinfo'];
   $config = $global['config'];

   $searchSwitch = $global['searchSwitch'];

   $func->loadstart("Connecting to $searchSwitch and adding VLAN(s), please wait ...", $searchSwitch);

   $count = $input->get('ids', 'all');
   $existingVlans = array_keys(collectVlanData($searchSwitch));
   $subnets = collectSubnetData($searchSwitch, $gridMaster);

   $statement = "SELECT name, ipaddress FROM lan_switch WHERE name = '$searchSwitch'";
   $queryResult = $db->query($statement);

   $ip = $queryResult[$searchSwitch]['ipaddress'];

   $success = array();
   $error = array();
   $logs = array();

   if ($func->ping($ip)) {
      $conn = $ssh->connect($ip, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);
   }

   $ibns = 0;

   if ($conn) {
      $output["terminal length 0"] = $ssh->command("terminal length 0");
      // Detect IBNS mode
      $ibnsMode = $ssh->command("authentication display config-mode");

      if (preg_match('/legacy/i', $ibnsMode)) {
         $ibns = 1;
      }
      if (preg_match('/new-style/i', $ibnsMode)) {
         $ibns = 2;
      }

      $ssh->command("configure terminal", 5);
      $ssh->command("file prompt quiet");

      for ($i = 0; $i <= $count; $i++) {
         $vlan_id = intval(filter_var($input->get("vlanID_$i", 'all'), FILTER_SANITIZE_STRING));
         $vlan_name = filter_var($input->get("vlanName_$i", 'all'), FILTER_SANITIZE_STRING);

         if ($ibns == 2) {
            $output["service-template MANAGED_LAN_DEFAULT_CRITICAL_AUTH_" . $vlan_id] = $ssh->command("service-template MANAGED_LAN_DEFAULT_CRITICAL_AUTH_" . $vlan_id);
            $output["access-group ACL-PERMIT-ANY"] = $ssh->command("access-group ACL-PERMIT-ANY");
            $output["exit"] = $ssh->command("exit");
         }

         if (preg_match('/swc/i', $searchSwitch)) {
            $vlan_ip = filter_var($input->get("vlanIP_$i", 'all'), FILTER_SANITIZE_STRING);
            $vlan_mask = intval(filter_var($input->get("vlanMask_$i", 'all'), FILTER_SANITIZE_STRING));
            $vlan_desc = filter_var($input->get("vlanDesc_$i", 'all'), FILTER_SANITIZE_STRING);

            $netmask = CIDRtoMask($vlan_mask);
            $subnet = long2ip(ip2long($vlan_ip) & ip2long($netmask));

            // TBD - this needs immediate attention - having continue without advising the issue to the user trying to perform a change will be confusing for the user
            if (empty($vlan_ip) || empty($vlan_mask) || empty($vlan_desc) || $vlan_mask < 16 || $vlan_mask > 32 || !filter_var($vlan_ip, FILTER_VALIDATE_IP) || !preg_match('/\d|MON|' . $vlan_name . '/i', $vlan_desc)) {
               $error_messages[] = "The data for creation of vlan $vlan_id is incorrect.";
            }

            $vlanFound = 0;
            $ipEqualsSubnet = 0;

            foreach ($subnets as $id => $value) {
               if ("$subnet/$vlan_mask" == $value['network'] && $vlan_id == $value['vlan_id'] && $vlan_name == $value['vlan_name']) {
                  $vlanFound++;
               }

               //If SVI ip is the same as one of the subnets from infoblox => raise ipEqualsSubnet
               if ("$vlan_ip/$vlan_mask" == $value['network']) {
                  $ipEqualsSubnet++;
               }
            }

            // TBD - this needs immediate attention - having continue without advising the issue to the user trying to perform a change will be confusing for the user
            if (!$vlanFound) {
               $error_messages[] = "The $vlan_id could not be found.";
            }

            if ($ipEqualsSubnet) {
               $error_messages[] = "The IP for creation of vlan $vlan_id is equal to the subnet";
            }

            if (!empty($error_messages)) {
               print $html->error_messages($error_messages, 'ERROR');
               continue;
            }

            $output["vlan $vlan_id"] = $ssh->command("vlan $vlan_id", 2);
            $output["name $vlan_name"] = $ssh->command("name $vlan_name", 2);
            $output["interface vlan $vlan_id"] = $ssh->command("interface vlan $vlan_id", 2);
            $output["description $vlan_desc"] = $ssh->command("description $vlan_desc", 2);
            $output["ip address $vlan_ip $netmask"] = $ssh->command("ip address $vlan_ip $netmask", 2);
            $output["exit"] = $ssh->command("exit", 2);

            // Create Policy map for the new vlan ID  only if VLAN id in the [200-299] range
            if ($ibns == 2 && $vlan_id > 200 && $vlan_id < 299) {
               $policyMapCmds = getPolicyMapCmds($vlan_id, $ibns);

               foreach ($policyMapCmds as $policyMapCmd) {
                  $output[$policyMapCmd] = $ssh->command($policyMapCmd);
               }
               $output["exit"] = $ssh->command("exit");
            }

            //Here verify if the SVI ip exists in infoblox, if not create a new one then
            $filter = array('ipv4addr' => "$vlan_ip");
            $objects = $gridMaster->getHostObject($filter);

            if (empty($objects)) {
               //Create host object - this is the correct data structure DO NOT CHANGE
               $data = array(
                  'name' => $searchSwitch . ".lan.emrsn.org",
                  'ipv4addrs' => array(
                     array(
                        'ipv4addr' => $vlan_ip,
                        'mac' => '00:00:00:00:00:00',
                     ),
                  ),
                  'configure_for_dns' => false
               );

               $logs[] = ["gridmaster" => $data];

               //$createHost = $gridMaster->createHostObject($data);

               // if ($createHost) { $success[] = "Host Record for $searchSwitch ($vlan_ip) has been created in Infoblox"; }
               // else { $error[] = "Could not create host record for $searchSwitch ($vlan_ip) in Infoblox"; }

            }

            $success[] = "$vlan_name added";

            $logs[] = [
               'VLAN_ID' => $vlan_id,
               'VLAN_Name' => $vlan_name,
               'VLAN_IP' => $vlan_ip,
               'VLAN_Mask' => $vlan_mask,
               'VLAN_Desc' => $vlan_desc,
            ];
         } else {
            $output["vlan $vlan_id"] = $ssh->command("vlan $vlan_id", 2);
            $output["name $vlan_name"] = $ssh->command("name $vlan_name", 2);
            $output["exit"] = $ssh->command("exit", 2);

            // Create Policy map for the new vlan ID  only if VLAN id in the [200-299] range
            if ($ibns == 2 && $vlan_id > 200 && $vlan_id < 299) {
               $policyMapCmds = getPolicyMapCmds($vlan_id, $ibns);

               foreach ($policyMapCmds as $policyMapCmd) {
                  $output[$policyMapCmd] = $ssh->command($policyMapCmd);
               }
               $output["exit"] = $ssh->command("exit");
            }

            $success[] = "$vlan_name added";

            $logs[] = [
               'VLAN_ID' => $vlan_id,
               'VLAN_Name' => $vlan_name,
            ];
         }

      }

      $output["end"] = $ssh->command("end");
      $output["copy running-config startup-config"] = $ssh->command("copy running-config startup-config");
      $output["configure terminal"] = $ssh->command("configure terminal");
      $output["file prompt alert"] = $ssh->command("file prompt alert");

      $output["end"] = $ssh->command("end");

      $ssh->disconnect();

      $func->loadfinish($searchSwitch);

      $logs[] = ["output" => $output];

      $logData = array(
         'action' => 'Create VLAN(s)',
         'object' => $searchSwitch,
         'data' => $logs,
         'status' => array('success' => $success, 'error' => $error),
      );

      $func->logchange($db, json_encode($logData));
      $func->loguser($db, $searchSwitch, 'create.vlans');

   } else {
      $func->loadfinish($searchSwitch);
      $error[] = "Could not connect to $searchSwitch - $ip";
   }

   if ($success) {
      print $html->success_message($success, "SUCCESS");
   }
   if ($error) {
      print $html->error_message($error, "ERROR");
   }

   $gridMaster->logout();
}

function CIDRtoMask($int)
{
   return long2ip(-1 << (32 - (int) $int));
}

function vlanLayer3Switch()
{
   global $global;

   $html = $global['html'];
   $func = $global['func'];

   $index = 0;

   $guide = array(
      "Vlan ID must be between 4 and 919",
      "VLAN ID must exist in infoblox",
      "VLAN Name, IP, Prefix, Description are read-only",
      "VLAN Name will be filled with the name of the VLAN ID from Infoblox",
      'VLAN IP and Prefix will be filled with next available IP from Infoblox',
      'VLAN Description will be filled automatically based on the VLAN ID and VLAN Name',
      "No duplicate values, excluding Prefix and Description"
   );

   print "<table>";
   print "<tbody class='tbodyClone' id='vlaninput_table'>";
   print $html->info_message($guide, "Validation Criteria");
   print "<div id='infoblox_spinner'>";
   print "</div>";
   print $html->hidden_text("ids", $count);

   print "<div class='ui labels'>";
   print "<div class='ui label'>
               Vlan ID
            </div>";
   print "<div class='ui label' style='margin-left: 14px;'>
               Vlan Name
            </div>";
   print "<div class='ui label' style='margin-left: 150px;'>
               IP Address
            </div>";
   print "<div class='ui label' style='margin-left: 79px;'>
               Prefix
            </div>";
   print "<div class='ui label' style='margin-left: 23px;'>
               Description
            </div>";
   print "</div>";

   do {
      print "<tr id='clonedInput0' class='clonedInput'>";
      print "<td>";
      print $html->input_text("vlanID_$index", $vlanID, 3, 4, array('style' => 'margin-bottom:10px;', 'placeholder' => 'VLAN ID'));
      print "</td>";
      print "<td>";
      print $html->input_text("vlanName_$index", $vlanID, 50, 25, array('style' => 'margin-bottom:10px; margin-left:10px;', 'placeholder' => 'VLAN Name'));
      print "</td>";
      print "<td>";
      print $html->input_text("vlanIP_$index", $vlanID, 15, 15, array('style' => 'margin-bottom:10px; margin-left:10px;', 'placeholder' => 'IP Address'));
      print "</td>";
      print "<td>";
      print $html->input_text("vlanMask_$index", $vlanID, 2, 4, array('style' => 'margin-bottom:10px; margin-left:10px;', 'placeholder' => 'Prefix'));
      print "</td>";
      print "<td>";
      print $html->input_text("vlanDesc_$index", $vlanID, 100, 25, array('style' => 'margin-bottom:10px; margin-left:10px;', 'placeholder' => 'Description'));
      print "</td>";
      print "<td><div class='clone' style='padding-bottom: 8px;'><i class='green large plus circle icon'></i> </div></td>";
      print "<td><div class='remove' style='padding-bottom: 8px;'><i class='red large minus circle icon'></i> </div></td>";
      print "</tr>";
      $index++;
   } while ($index < $count);

   print "</tbody>";
   print "</table>";
}

function vlanLayer2Switch()
{
   global $global;

   $html = $global['html'];

   $searchSwitch = $global['searchSwitch'];

   $index = 0;

   $guide = array(
      "VLAN ID must be between 4 and 919",
      "VLAN ID must not exist on $searchSwitch",
      "VLAN Name must not contain EnterName",
      "VLAN Name must follow pattern VLID_Name, where ID is the VLAN ID",
      "No duplicate values"
   );

   print "<table id='vlaninput_table'>";
   print "<tbody class='tbodyClone'>";
   print $html->hidden_text("ids", $count);
   print $html->info_message($guide, "Validation Criteria");

   do {
      print "<tr id='clonedInput0' class='clonedInput'>";
      print "<td>" . $html->input_text("vlanID_$index", $vlanID, 3, 7, array('style' => 'margin-bottom:10px;', 'placeholder' => 'VLAN ID')) . "</td>";
      print "<td>" . $html->input_text("vlanName_$index", $vlanID, 50, 25, array('style' => 'margin-bottom:10px; margin-left:10px;', 'placeholder' => 'VLAN Name')) . "</td>";
      print "<td><div class='clone'><i class='green large plus circle icon'></i> </div></td>";
      print "<td><div class='remove'><i class='red large minus circle icon'></i> </div></td>";
      print "</tr>";
      $index++;

   } while ($index < $count);

   print "</tbody>";
   print "</table>";
}

function showUpdateBannerModal()
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];

   $tab = $global['tab'];
   $searchSwitch = $global['searchSwitch'];

   $selectBanners = [];

   print $html->startmodal("Update Banner for $searchSwitch", true);
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->info_message('The option you see below is the current banner', 'Select a banner');
   print $html->select('location_banner', $selectBanners, $banner);
   print '<br><br>';
   print "<div class='cliresult' id='banner_content' style='overflow-y:auto; overflow-x:auto; height:200px;'></div>";
   print '<br><br>';
   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('tab', $tab);
   print $html->hidden_text('siteSection', $siteSection);
   print $html->endmodalcontent();
   print $html->submit('submit', 'Cancel', array('size' => 'large', 'color' => 'red', 'align' => 'right floated'));
   print $html->submit('submit', 'Apply Banner', array('size' => 'large', 'color' => 'green', 'align' => 'right floated'));
   print "<br><br><br><br>";
   print $html->endform();
   print $html->endmodal();

}

function applyBanner()
{

   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $config = $global['config'];

   $searchSwitch = $global['searchSwitch'];
   $chosenBannerCode = $input->get('location_banner', 'all');

   if (preg_match('/Select/i', $chosenBannerCode)) {
      print $html->error_message('Invalid banner choice.', 'ERROR');
      return 0;
   }

   $query = "SELECT ipaddress, name, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation FROM lan_switch WHERE name LIKE '$searchSwitch'";
   $queryResult = $db->query($query);

   foreach ($queryResult as $key => $value) {
      $searchSwitch = $value['name'];
      $currentIP = $value['ipaddress'];

      $currentSysLocation = $value['sysLocation'];
      $newSysLocation = '';

      if (preg_match('/B[0-9].{2}/', $currentSysLocation)) {
         preg_match('/B[0-9].{2}/', $currentSysLocation, $match);
         $currentBannerCode = $match[0];
         $newSysLocation = str_replace($currentBannerCode, $chosenBannerCode, $currentSysLocation);
      } else if (preg_match("/S[0-9]+,[' ']+L[0-9]+/", $currentSysLocation)) {
         $exploded = explode(',', $currentSysLocation);
         $newPart = trim($exploded[0]) . ', ' . trim($exploded[1]) . ', ' . $chosenBannerCode . ',';
         $newSysLocation = preg_replace("/S[0-9]+,[' ']+L[0-9]+([',',[' '])+/", $newPart, $currentSysLocation);
      }

      if (!preg_match("/S[0-9]+,[' ']+L[0-9]+,[' '].+" . $chosenBannerCode . "/", $newSysLocation)) {
         $errors[] = "Banner update failed for switch $searchSwitch - $currentIp due to invalid SysLocation $newSysLocation";
      } else {
         $result = connAndExec($currentIP, $chosenBannerCode, $currentBannerCode, $currentSysLocation, $newSysLocation, $searchSwitch);
         if ($result['success']) {
            $success[] = $result['success'];
         }
         if ($result['errors']) {
            $errors[] = $result['errors'];
         }
      }
   }

   if ($success) {
      print $html->success_message($success, "SUCCESS");
   }
   if ($errors) {
      print $html->error_message($errors, "ERROR");
   }

}

function checkIfFlexConnect($intinfo, $vlans)
{
   global $global;
   // We assume port is a flexconnect
   $return = 1;

   foreach ($intinfo['iftrunkvlan'] as $allowedVlan) {
      // If we find any vlan that does not contain either of the key words or vlan id not in the (20-29) range 
      // then the port is probably a normal trunk
      if (
         !(preg_match('/emrdata|wifi|wireless|mgmt|management/i', $vlans[$allowedVlan]) ||
            ($allowedVlan > 20 && $allowedVlan < 29))
      ) {
         $return = 0;
      }
   }

   if (!preg_match('/FCAP/', $intinfo['ifalias'])) {
      $return = 0;
   }

   if (!$intinfo['iftrunkvlan']) {
      $return = 0;
   }

   return $return;
}

function showUpdateServiceNowModal()
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];

   $tab = $global['tab'];
   $searchSwitch = $global['searchSwitch'];

   print $html->startmodal("Update Service Now details for $searchSwitch", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   $query = "SELECT name, ipaddress, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sn')) as sn " .
      " FROM lan_switch WHERE name LIKE '$searchSwitch'";
   $switchDetails = $db->query($query);

   $snowInfo = getSwitchFromSnow($switchDetails[$searchSwitch]['sn']);


   print "<div class='ui one column grid'>";
   print "<div class='column'>";

   print "Point of Contact (List of users semicolon (;) separated)";

   print "<br><br>";

   print $html->textarea(
      'u_local_poc',
      preg_replace('/,/', '; ', $snowInfo['u_local_poc']['display_value']),
      100,
      5,
      array('placeholder' => 'Local POC')
   );
   print $html->hidden_text('u_local_poc_snow', json_encode($snowInfo['u_local_poc']));
   print "<br><font color='blue'>As you type, an automatic search in AD will be performed providing you a " .
      "list of available AD accounts based on your search. " .
      "<br>Only valid AD users are accepted as POC</font>";
   print "<br>";
   print "<br>";
   print "</div></div>";

   print $html->endmodalcontent();

   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('tab', $tab);

   print $html->submit(
      'submit',
      'Update ServiceNow',
      array(
         'size' => 'tiny',
         'color' => 'green',
         'align' => 'right floated'
      )
   );

   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();

}

function updateServiceNow()
{

   global $global;

   $snow = $global['snow'];
   $html = $global['html'];
   $func = $global['func'];
   $db = $global['db'];

   $searchSwitch = $global['searchSwitch'];

   $func->loadstart();

   $snowData = gatherSnowData();

   $rc = $snow->importSwitch($snowData);

   if ($rc) {
      print $html->success_message("Service Now has been updated for <b>$searchSwitch</b> ", "SUCCESS");
   } else {
      print $html->error_message("Could not update Service Now for <b>$searchSwitch</b> ", "ERROR");
   }

   $logData = array(
      'object' => $searchSwitch,
      'action' => 'updateServicenow',
      'data' => $snowData,
      'status' => array('activity' => ($rc) ? 'Success' : 'Failed'),
   );

   $func->logchange($db, json_encode($logData));

   $func->loadfinish();
}

function gatherSnowData()
{
   global $global;

   $input = $global['input'];
   $db = $global['db'];

   $searchSwitch = $global['searchSwitch'];

   $query = "SELECT name, ipaddress, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sn')) as sn " .
      " FROM lan_switch WHERE name LIKE '$searchSwitch'";
   $switchDetails = $db->query($query);

   $return['u_ip_address'] = $switchDetails[$searchSwitch]['ipaddress'];
   $swname = explode('.', $switchDetails[$searchSwitch]['name']);

   $return['u_name'] = strtolower($swname[0]);

   if ($switchDetails[$searchSwitch]['sn']) {
      $return['u_serial_number'] = $switchDetails[$searchSwitch]['sn'];
   }

   // Gather the list of local poc
   $inputValue = $input->get('u_local_poc', 'all');

   // For the already existing poc in SNOW we need to keep the sys_ids
   $existingPoc = json_decode($input->get('u_local_poc_snow', 'all'), true);

   $snowPoc['id'] = explode(',', $existingPoc['value']);
   $snowPoc['name'] = explode(', ', $existingPoc['display_value']);

   $items = explode(';', $inputValue);

   foreach ($items as $id => $item) {
      $item = trim($item);

      if (!$item || $item == " ")
         continue;
      // we need to keep the existing poc info when overwriting the data
      if (in_array($item, $snowPoc['name'])) {
         foreach ($snowPoc['name'] as $id => $name) {
            if ($item == $name) {
               $poc[] = $snowPoc['id'][$id];
            }
         }
      } else {
         // if there is a valid ad account get the sys id from snow and use that
         if (getUserSnowSysId($item)) {
            $poc[] = getUserSnowSysId($item);
         }
         // if it is an email address use it as it is 
         // commenting this out as for switches this is not allowed
         // else if (filter_var($item, FILTER_VALIDATE_EMAIL)) { $poc[] = $item; }
      }
   }

   $return['u_local_poc'] = implode(',', $poc);

   return $return;
}

function getUserSnowSysId($search)
{

   global $global;

   $adbasedn = $global['adbasedn'];
   $ldap = $global['ldap'];
   $snow = $global['snow'];

   $filter = "(&(objectClass=user)(|(displayname=$search)" .
      "(mail=$search)(samaccountname=$search)))";

   $justthese = array("sAMAccountName", "cn");

   $object = $ldap->search($adbasedn, $filter, $justthese);

   $samaccountname = $object[0]['samaccountname'][0];

   if ($samaccountname) {
      $params = array(
         "user_name" => $samaccountname,
         'sysparm_fields' => 'sys_id',
         'sysparm_display_value' => 'all'
      );

      $result = $snow->getUser($params);

      $return = $result['result'][0]['sys_id']['value'];
   }
   return $return;
}

function showConfigureInterfaceModal($interfaces, $vlans)
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $snmp = $global['snmp'];
   $oids = $global['oids'];
   $tool_name = $global['tool_name'];
   $func = $global["func"];

   $switches = $global['switches'];

   $searchSwitch = $global['searchSwitch'];
   $switchInterface = $global['switchInterface'];

   $selectIfConfig = $global['selectIfConfig'];

   $currentIfConfig = $global['currentIfConfig'];

   // We will allow changing port mode from Access to AP only if port is up&access&cdp neigh is an AP
   if ($currentIfConfig['ifoperstatus'] == '1' && preg_match('/cisco air|C9120AX|C9130AX|CW9163|CW9166/i', $currentIfConfig['ifcdpdevice'])) {
      $portModes = array(
         '2' => "Access Port",
         '22' => "Access Port for Local Mode AP",
         '1' => "Trunk Port for Flexconnect AP",
      );
   } else {
      $portModes = array('2' => "Access Port");
   }

   // We need to restrict the wireless vlans for configuring flex connect port
   $nativeVlans = array('' => '');
   $wirelessVlans = array('' => '');

   foreach ($vlans as $vlanId => $vlanName) {
      if (preg_match('/networkmgmt/i', $vlanName)) {
         $nativeVlans[$vlanId] = $vlanName;
      }
      if (preg_match('/emrdata|wifi|wireless|networkmgmt/i', $vlanName) || ($vlanId > 20 && $vlanId < 29)) {
         $wirelessVlans[$vlanId] = $vlanName;
      }
   }

   // Begin modal
   print $html->startmodal("Configure $searchSwitch/$switchInterface", true);

   print $html->startform(
      array(
         'id' => 'configureInterface',
         'method' => 'post',
         'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)
      )
   );

   print $html->startmodalcontent();

   // Make sure they are not changing the interface in the http link
   if (hash('sha256', $switchInterface . $tool_name) != $currentIfConfig['switchInterface']) {
      print "<div class='ui one grid'>";
      print "<div class='column'>";
      print "<br><br>";
      print $html->warning_message('Something went wrong. Please close the window and try again.', 'WARNING');
      print "</div></div>";
      $error[] = 'Something went wrong. Please close the window and try again.';
   } else {
      print "<div class='ui two column grid'>";
      print "<div class='column'>";
      print "Port Mode";
      print "<br>";

      // We need to detect if the port is a local mode ap or normal access port (ifmode will be 2 for both)
      $detectPortMode = ($currentIfConfig['ifmode'] == 2 && preg_match('/mgmt|management/i', $vlans[$currentIfConfig['ifvlan']]) && preg_match('/LMAP/', $currentIfConfig['ifalias']))
         ? 22 : $currentIfConfig['ifmode'];

      // Removing Vlan 2 and vlan 3 from the VLANs available for configuration of an access port
      // This needs to happen after the native vlan list is built
      unset($vlans[2]);
      unset($vlans[3]);

      $not_voice_vlans = [];
      $voice_vlans = [];

      $not_voice_vlans[""] = "";

      foreach ($vlans as $id => $vlan) {
         if (preg_match("/remove/i", $id)) {
            //$not_voice_vlans[$id] = "remove";
            $voice_vlans[$id] = "remove";
            continue;
         }

         if (($id >= 300 && $id <= 399) || preg_match('/voice/i', $vlan)) {
            $voice_vlans[$id] = $vlan;
         } else {
            $not_voice_vlans[$id] = $vlan;
         }
      }

      print $html->select('ifmode', $portModes, $detectPortMode);
      print "<br><br>";
      print "<div id = 'datavlan'>";
      print "VLAN";
      print "<br>";
      print $html->select('ifvlan', $not_voice_vlans, $currentIfConfig['ifvlan']);
      print "</div>";
      print "<div id = 'wirelessdatavlan'>";
      print "VLAN";
      print "<br>";
      print $html->select('ifwirelessvlan', $nativeVlans, $currentIfConfig['ifvlan']);
      print "</div>";

      // If the port will be configured as a flexconnect trunk then allow multiple vlans to be selected
      print "<div id = 'flextrkvlans'>";
      print "What DATA VLANs would you like to add?";
      print "<br>";
      print '<select multiple="multiple" class="ui fluid search dropdown" id="trunkvlans" ' .
         'multiple="" name="trunkvlans[]" autocomplete="on">';

      foreach ($wirelessVlans as $vlanId => $vlanName) {
         $is_selected = '';

         if (preg_match('/remove/i', $vlanName)) {
            continue;
         }

         foreach ($currentIfConfig['iftrunkvlan'] as $selected) {
            if ($vlanId == $selected) {
               $is_selected = 'selected';
            }
         }

         print '<option value=' . $vlanId . ' ' . $is_selected . '>' . $vlanName . '</option>';
      }

      print "</select>";
      print "</div>";
      print "<br><br>";


      print "<div id = 'voicevlan'>";
      print "Voice VLAN";
      print "<br>";
      print $html->select('ifvoicevlan', $voice_vlans, $currentIfConfig['ifvoicevlan']);
      print "<br><br>";
      print "</div>";

      print "<div id = 'nativevlan'>";
      print "Native VLAN (Usually Management VLAN)";
      print "<br><br>";
      print $html->select('ifnativevlan', $nativeVlans, $currentIfConfig['ifnativevlan']);
      print "<br><br>";

      print "</div>";
      print "Description";
      print "<br>";
      print $html->input_text(
         'ifalias',
         htmlspecialchars($currentIfConfig['ifalias'], ENT_QUOTES),
         50,
         40,
         array('placeholder' => 'Description')
      );
      print "<br><br>";

      echo "<div id='incorrectIfDescription'></div>";

      print "</div>";

      print "<div class='column'>";
      print "Admin Status";
      print "<br>";
      print $html->select(
         'ifadminstatus',
         $selectIfConfig['adminStatus'],
         $selectIfConfig['adminStatus'][$currentIfConfig['ifadminstatus']]
      );

      print "<div id='flexadminstatus'>";
      print "<br>";
      print $html->warning_message('If disabling a Central FlexConnect Access Point, please ensure the EOC team are aware
                  as an incident will be generated ', 'WARNING');
      print "</div>";
      print "<div id='localadminstatus'>";
      print "<br>";
      print $html->warning_message('If disabling a local mode Access Point, please ensure EOC team are aware, 
                 or the Wireless LAN Controller is in temporarily in maintenance mode in SolarWinds Orion', 'WARNING');
      print "</div>";
      print "<br><br>";

      print "<div id='speed'>";
      print "Speed";
      print "<br>";
      print $html->select('ifspeed', $selectIfConfig['speed'], ($currentIfConfig['ifspeed'] / 1000000));
      print "<br><br>";
      print "Duplex";
      print "<br>";
      print $html->select('ifduplex', $selectIfConfig['duplex'], $selectIfConfig['duplex'][$currentIfConfig['ifduplex']]);
      print "<br><br>";
      print "</div>";
      print "</div>";
      print "</div>";
   }

   print $html->endmodalcontent();

   $alias_str = $currentIfConfig["ifalias"];
   $mode_int = $currentIfConfig["ifmode"];

   if ($mode_int == 2 && !preg_match("/FCAP|LMAP|L@CK/i", $alias_str)) {
      echo "<div id='bounce_port_div'>"; {
         echo "<div>"; {
            echo $html->semantic_ui_checkbox("bounce_port_checkbox", "bounce_it_up_fam", "Bounce Port");
         }
         echo "</div>";

         echo "<div id='warning_message_div' style='margin-top: 10px; display: none;'>"; {
            echo $html->warning_message("Bouncing the ports can be tricky. If you are sure, you're free to proceed", "Warning");
         }
         echo "</div>";
      }
      echo "</div>";
   }

   print "<br><br><br>";

   $currentIfConfig64 = base64_encode(json_encode($currentIfConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));

   print $html->hidden_text('tab', 'Switch');
   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('switchInterface', $switchInterface);
   print $html->hidden_text('currentIfConfig', $currentIfConfig64);

   if (!$error) {
      print "<div id= 'configureinterfacebutton'>";
      print $html->submit(
         'submit',
         'Configure Interface',
         array('size' => 'tiny', 'color' => 'green', 'align' => 'right floated')
      );
      print "</div>";
   }

   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();

}

function showConfigurePortChannelModal($interfaces, $vlans)
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $snmp = $global['snmp'];
   $oids = $global['oids'];
   $tool_name = $global['tool_name'];

   $switches = $global['switches'];

   $searchSwitch = $global['searchSwitch'];
   $switchInterface = $global['switchInterface'];

   $selectIfConfig = $global['selectIfConfig'];

   $currentIfConfig = $global['currentIfConfig'];

   print $html->startmodal("Configure VLANs on $searchSwitch/$switchInterface", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   // Make sure they are not changing the interface in the link
   if ($switchInterface != $currentIfConfig['ifdescr']) {
      print "<div class='ui one grid'>";
      print "<div class='column'>";
      print "<br><br>";
      print $html->warning_message('Something went wrong. Please close the window and try again.', 'WARNING');
      print "</div></div>";
      $error[] = 'Something went wrong. Please close the window and try again.';
   } else {
      print "<div class='ui one column grid' style='height:350px;'>";
      print "<div class='column'>";
      print "Select VLANs to allow on trunk";
      print "<br/><br/>";
      print '<select multiple="multiple" class="ui fluid search dropdown" id="trunkvlans" ' .
         'multiple="" name="trunkvlans[]" autocomplete="on">';

      foreach ($vlans as $vlanId => $vlanName) {
         $is_selected = '';

         if (preg_match('/remove/i', $vlanName)) {
            continue;
         }

         foreach ($currentIfConfig['iftrunkvlan'] as $selected) {
            if ($vlanId == $selected) {
               $is_selected = 'selected';
            }
         }

         print '<option value=' . $vlanId . ' ' . $is_selected . '>' . $vlanName . '</option>';
      }

      print "</select>";

      print "</div>";
      print "</div>";
   }

   print $html->endmodalcontent();

   print "<br><br><br>";

   $currentIfConfig64 = base64_encode(json_encode($currentIfConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));

   print $html->hidden_text('tab', 'Switch');
   print $html->hidden_text('searchSwitch', $searchSwitch);
   print $html->hidden_text('switchInterface', $switchInterface);
   print $html->hidden_text('currentIfConfig', $currentIfConfig64);

   if (!$error) {
      print $html->submit(
         'submit',
         'Configure Port-Channel',
         array('size' => 'tiny', 'color' => 'green', 'align' => 'right floated')
      );
   }
   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();
}

function showRunInterfaceConfigModal()
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $func = $global['func'];
   $ssh = $global['ssh'];

   $config = $global['config'];

   $switches = $global['switches'];

   $searchSwitch = $global['searchSwitch'];
   $switchInterface = $global['switchInterface'];

   $devip = $switches[$searchSwitch]['ipaddress'];

   print $html->startmodal("Show Run Config for  $searchSwitch/$switchInterface", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   print "<div class='ui one column grid'>";
   print "<div class='column'>";
   print $html->hidden_text('modal', 'RunInterfaceConfig');
   print $html->hidden_text('devip', $devip);
   print $html->hidden_text('switchInterface', $switchInterface);
   print "<br><br>";
   print '<div class="ui indicating progress" id ="runCliProgress" data-value="1" data-total="2">
                     <div class="bar"></div>
                 <div class="label">Running CLI Command via SSH</div>
           </div>';

   print "<br>";
   print "<div class='runConfig' style='overflow-y:auto; overflow-x:auto; height:200px;'>";
   print "</div>";
   print "</div>";
   print "</div>";

   print $html->endmodalcontent();

   print "<br><br><br>";

   print $html->hidden_text('tab', 'Switch');
   print $html->hidden_text('searchSwitch', $searchSwitch);

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();
}

function showRunCLICommandModal($interfaces, $vlans)
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $func = $global['func'];
   $input = $global['input'];
   $config = $global['config'];
   $snmp = $global['snmp'];
   $oids = $global['oids'];
   $ssh = $global['ssh'];
   $submit = $global['submit'];


   $permission = $global['permission'];

   $switches = $global['switches'];
   $cliCommands = $global['cliCommands'];

   $searchSwitch = $global['searchSwitch'];
   $devip = $switches[$searchSwitch]['ipaddress'];

   $allinterfaces = array_column(array_values(array_filter($interfaces, function ($info) {
      return !preg_match('/unrouted|null|stack/i', $info['ifdescr']);
   })), 'ifdescr');
   $physicalInts = array_column(array_values(array_filter($interfaces, function ($info) {
      return !preg_match('/Vlan|Null|VLAN|Stack|Port-channel|loopback|bluetooth/i', $info['ifdescr']);
   })), 'ifdescr');

   $portchannels = preg_grep("/port-channel/i", $allinterfaces);

   // If the permission is restricted to env then we need to remove some of the commands 
   if (preg_match('/env/', $permission)) {
      unset($cliCommands['show running-config']);
      unset($cliCommands['show startup-config']);
      unset($cliCommands['show tech-support']);
      unset($cliCommands['show aaa servers']);
      unset($cliCommand['test cable-diagnostics tdr interface filter']);
   }

   print $html->startmodal("Run CLI Commands on $searchSwitch", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   // Some of the commands are composed from base command and extra filter. 

   print "<div class='ui two column grid'>";

   print "<div class='column'>";
   print $html->hidden_text('modal', 'runCLICommands');
   print $html->select('cliCommand', $cliCommands, $cliCommand['cliCommand']);
   print $html->hidden_text('devip', $devip);
   print "</div>";

   print "<div class='column'>";
   print $html->select('allinterfaces', $allinterfaces, array(), array('style' => 'display:none'));

   print $html->select('interface', $physicalInts, array(), array('style' => 'display:none'));
   print $html->select('portchannel', $portchannels, array(), array('style' => 'display:none'));
   print $html->input_text('ip_address', '', 60, 20, array('style' => 'display:none'));
   print $html->input_text('mac_addr', '', 60, 20, array('style' => 'display:none', 'placeholder' => '48 bit mac addr (H.H.H)'));
   print "</div>";

   print "<div id='TDRWarning' style='display: none'>";

   print $html->warning_message("Depending on switch model, a TDR Cable test may cause the port and devices on this port to experience network connectivity problems during the test. Please ensure this port and devices connected can have it's network connection interrupted before proceeding. Testing takes about 30 seconds to a minute to complete and there is no notification that the testing is complete. Please use the 'show cable-diagnostics tdr interface' command to see the results.", "WARNING");
   print "<br/><br/>";
   print "</div>";
   print "</div>";
   print $html->endmodalcontent();

   print $html->hidden_text('tab', 'Switch');
   print $html->hidden_text('searchSwitch', $searchSwitch);

   // On click this button will trigger ajax call which will perform the actual run cli
   print $html->submit('runCommand', 'Run Command', array('type' => 'button', 'size' => 'tiny', 'color' => 'green', 'align' => 'right floated'));

   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br>";
   print '<div class="ui indicating progress" id ="runCliProgress" data-value="1" data-total="2">
                     <div class="bar"></div>
                 <div class="label">Running CLI Command via SSH</div>
           </div>';

   print "<br>";

   print $html->endform();
   print "<h5 class='ui dividing header'>Result</h5>";
   print "<div class='cliresult' style='overflow-y:auto; overflow-x:auto; height:300px;'>";
   print "</div>";

   print "<br><br>";

   print $html->endmodal();

}

function configureInterface()
{

   global $global;

   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $html = $global['html'];
   $config = $global['config'];
   $func = $global['func'];
   $tool_name = $global['tool_name'];

   $switches = $global['switches'];
   $searchSwitch = $global['searchSwitch'];
   $switchInterface = $global['switchInterface'];
   $currentIfConfig = $global['currentIfConfig'];
   $selectIfConfig = $global['selectIfConfig'];

   if (hash('sha256', $switchInterface . $tool_name) != $currentIfConfig['switchInterface']) {
      print $html->error_message("Something went wrong. Please close the window and try again.", "ERROR");
      return;
   }

   $devip = $switches[$searchSwitch]['ipaddress'];

   // Check the submitted data against the current interface configuration
   // We will apply configure port only if there are changes
   $configData = array(
      'mode' => ($currentIfConfig['ifmode'] != $input->get('ifmode', 'all')) ? $input->get('ifmode', 'all') : '',
      'vlan' => ($currentIfConfig['ifvlan'] != $input->get('ifvlan', 'all')) ? $input->get('ifvlan', 'all') : '',
      'wirelessvlan' => ($currentIfConfig['ifvlan'] != $input->get('ifwirelessvlan', 'all')) ?
         $input->get('ifwirelessvlan', 'all') : '',
      'trunkvlans' => (array_diff($currentIfConfig['iftrunkvlan'], $input->get('trunkvlans', 'all')) ||
         array_diff($input->get('trunkvlans', 'all'), $currentIfConfig['iftrunkvlan'])) ?
         $input->get('trunkvlans', 'all') : array(),
      'voiceVlan' => ($currentIfConfig['ifvoicevlan'] != $input->get('ifvoicevlan', 'all')) ?
         $input->get('ifvoicevlan', 'all') : '',
      'nativevlan' => ($currentIfConfig['ifnativevlan'] != $input->get('ifnativevlan', 'all')) ?
         $input->get('ifnativevlan', 'all') : '',
      'description' => ($currentIfConfig['ifalias'] != $input->get('ifalias', 'all')) ? $input->get('ifalias', 'all') : '',
      'adminStatus' => ($selectIfConfig['adminStatus'][$currentIfConfig['ifadminstatus']] != $input->get('ifadminstatus', 'all')) ? $input->get('ifadminstatus', 'all') : '',
      'speed' => ($currentIfConfig['ifspeed'] / 1000000 != $input->get('ifspeed', 'all')) ?
         $input->get('ifspeed', 'all') : '',
      'duplex' => ($selectIfConfig['duplex'][$currentIfConfig['ifduplex']] != $input->get('ifduplex', 'all')) ?
         $input->get('ifduplex', 'all') : '',
      'flexconnect' => $input->get('flexConnect', 'all'),
      "bounce_port" => $input->get("bounce_port_checkbox", "all") ? true : false,
   );

   $configData = array_filter($configData);

   if ($configData) {
      $func->loadstart("Connecting to $searchSwitch, please wait ...", $searchSwitch);
      $ibns = 0;
      $connected = $ssh->connect($devip, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);

      $restartPort = 0;

      if (!$connected) {
         $errors[] = "Could not SSH to device $searchSwitch";
         $func->loadfinish($searchSwitch);
      } else {
         $ssh->command("terminal length 0");

         $ibnsMode = $ssh->command("authentication display config-mode");
         if (preg_match('/legacy/i', $ibnsMode)) {
            $ibns = 1;
         }
         if (preg_match('/new-style/i', $ibnsMode)) {
            $ibns = 2;
         }

         // Connect to device and run configuration commands on the selected interface
         $currentConfig = $ssh->command("show run interface " . $switchInterface);

         $ssh->command("configure terminal");
         $ssh->command("file prompt quiet");

         if ($configData['mode'] && !($configData['mode'] == 22 && $currentIfConfig['ifmode'] == 2)) {
            // Port Mode was changed
            $ssh->command("default interface " . $switchInterface);
            // Need a flag to restart the port after the if config is ready
            $restartPort = 1;
         }

         // Entering config interface 
         $ssh->command("interface " . $switchInterface);

         if ($configData["bounce_port"] === true) {
            $ssh->command("shut");

            sleep(5);

            $ssh->command("no shut");
         }

         // If port mode changed to trunk
         if ($configData['mode'] == 1) {
            $ssh->command("switchport trunk encapsulation dot1q");
            $ssh->command("switchport mode trunk");
            if ($configData['nativevlan']) {
               $ssh->command("switchport access vlan {$configData['nativevlan']}");
            }
            $ssh->command("ip dhcp snooping trust");

            // Device tracking commands
            // If IOS-XE we need to run command device-tracking attach-policy disable_ipdt
            // If Non XE we need to run command no ip device tracking max
            $showVersion = $ssh->command("do show version", 5);
            if (preg_match('/ios-xe/i', $showVersion)) {
               $deviceTrack = $ssh->command("device-tracking attach-policy disable_ipdt", 5);
               // In case policy is not yet defined we need to do this now (in the global config mode)  and run the command again
               if (preg_match('/Policy disable_ipdt not configured for feature Device-tracking/i', $deviceTrack)) {
                  $ssh->command("exit");
                  $ssh->command("device-tracking policy disable_ipdt");
                  $ssh->command("trusted-port");
                  $ssh->command("device-role switch");
                  $ssh->command("no protocol ndp");
                  $ssh->command("no protocol dhcp6");
                  $ssh->command("no protocol arp");
                  $ssh->command("no protocol udp");
                  $ssh->command("tracking disable");
                  $ssh->command("exit");
                  $ssh->command("interface " . $switchInterface);
                  $deviceTrack = $ssh->command("device-tracking attach-policy disable_ipdt", 5);
               }
            } else {
               $deviceTrack = $ssh->command("no ip device tracking max");
            }

            $ssh->command("spanning-tree portfast");
            $ssh->command("spanning-tree bpduguard enable");
         }

         // This is needed because LAN eng team expects service-policy update on service policy even when the port type isn't changing.
         if ($configData['vlan'] && $currentIfConfig['ifmode'] == 2) {
            if ($ibns == 1) {
               $authCmds = getAuthCmdsV1($configData, $searchSwitch);
            }
            if ($ibns == 2) {
               $authCmds = getAuthCmdsV2($configData, $currentIfConfig, $searchSwitch);
            }

            foreach ($authCmds as $authCmd) {
               $configData['authCmds'][] = $authCmd;
               $ssh->command($authCmd);
            }
         }

         // Port changed to access mode (exclude the change from access to access port for AP or the one changed to trunk
         if (($configData['mode'] == 2 || /* ($currentIfConfig['ifmode'] == 2 && $configData['mode'] != 1) ||*/ ($configData['mode'] == 22 && $currentIfConfig['ifmode'] != 2))) {
            $ssh->command("switchport mode access");
            $ssh->command("spanning-tree portfast");
            $ssh->command("spanning-tree bpduguard enable");

            // Based on the port config data/data+voice and site mode we apply the auth commands
            if ($ibns == 1) {
               $authCmds = getAuthCmdsV1($configData, $searchSwitch);
            }
            if ($ibns == 2) {
               $authCmds = getAuthCmdsV2($configData, $currentIfConfig, $searchSwitch);
            }

            foreach ($authCmds as $authCmd) {
               $configData['authCmds'][] = $authCmd;
               $ssh->command($authCmd);
            }
         }

         // We need to keep the old config for desc, speed, admin status as the port was reset
         if ($configData['mode']) {
            $ssh->command($selectIfConfig['adminStatus'][$currentIfConfig['ifadminstatus']]);
            $ssh->command("description " . $currentIfConfig['ifalias']);
         }

         if ($configData['nativevlan']) {
            $ssh->command("switchport trunk native vlan " . $configData['nativevlan']);
            array_push($configData['trunkvlans'], $configData['nativevlan']);
         }

         if ($configData['trunkvlans']) {
            // If the native vlan was not change we still need to keep the current one on the trunk as tagged
            if (!$configData['nativevlan'] && $currentIfConfig['ifnativevlan']) {
               array_push($configData['trunkvlans'], $currentIfConfig['ifnativevlan']);
            }
            // If the port was transition to access mode skip this step 
            if (!preg_match('/2/', $configData['mode'])) {
               $ssh->command("switchport trunk allowed vlan " . implode(',', $configData['trunkvlans']));
            }
         }

         // Configure interface description
         if ($configData['description']) {
            if ($configData['description'] != 'no description') {
               $ssh->command("description " . $configData['description']);
            } else {
               $ssh->command("no description");
            }
         }

         // Configure interface VLAN for access ports
         if ($configData['vlan']) {
            if (!preg_match('/remove/i', $configData['vlan'])) {
               $ssh->command("switchport access vlan " . $configData['vlan']);
               // If authorization is enabled for this port then we need an extra command to sync 
               if (preg_match('/authentication event/', $currentConfig)) {
                  $ssh->command("authentication event server dead action reinitialize vlan  " . $configData['vlan']);
               }
               $ssh->command("spanning-tree portfast");
               $ssh->command("spanning-tree bpduguard enable");
            } else {
               $ssh->command("switchport access vlan 1");
               $ssh->command("no spanning-tree portfast");
               $ssh->command("no spanning-tree bpduguard enable");
            }
         }

         // Configure interface VLAN for access ports
         if ($configData['wirelessvlan']) {
            $ssh->command("switchport access vlan " . $configData['wirelessvlan']);
            $ssh->command("spanning-tree portfast");
            $ssh->command("spanning-tree bpduguard enable");
            // If authorization is enabled for this port then we need an extra command to sync 
            if (preg_match('/authentication event/', $currentConfig)) {
               $ssh->command("authentication event server dead action reinitialize vlan  " . $configData['vlan']);
            }
         }
         // Configure voice VLAN on the interface
         if ($configData['voiceVlan']) {
            if (!preg_match('/remove/i', $configData['voiceVlan'])) {
               $ssh->command("switchport voice vlan " . $configData['voiceVlan']);
               $ssh->command("no authentication host-mode single-host");
               $ssh->command("authentication event server dead action authorize voice");
               $ssh->command("authentication host-mode multi-domain");
            } else {
               $ssh->command("no switchport voice vlan");
               $ssh->command("no authentication event server dead action authorize voice");
               $ssh->command("no authentication host-mode multi-domain");
               $ssh->command("authentication host-mode single-host");
            }
         }

         // Configure admin status of the interface (shut/ no shut) 
         if ($configData['adminStatus']) {
            $ssh->command($configData['adminStatus']);
         }

         if ($configData['speed']) {
            $ssh->command("speed " . $configData['speed']);
         }

         if ($configData['duplex']) {
            $ssh->command("duplex " . $configData['duplex']);
         }

         if ($restartPort) {
            $warning[] = "Port $switchInterface was bounced as its mode was changed. 
                   Please give 2-3 min for the neighbour to become online and " .
               "click Connect to refresh the CDP Neighbour details";
            $ssh->command("shut");
            $ssh->command("no shut");
         }

         $ssh->command("end");

         //$result    = $ssh->command("show run interface $switchInterface");       

         $success[] = "Interface $switchInterface has been configured";

         // $success[] = "<pre>".$result."</pre>";
         $ssh->command("copy running-config startup-config");
         $ssh->command("conf t");
         $ssh->command("file prompt alert");
         $ssh->command("end");

         $func->loadfinish($searchSwitch);
      }

      $ssh->disconnect();

      if ($success) {
         print $html->success_message($success, 'SUCCESS');
         $func->loguser($db, $searchSwitch, "change.switchport");
      }
      if ($warning) {
         print $html->warning_message($warning, "NOTE");
      }
      if ($errors) {
         print $html->error_message($errors, 'ERROR');
      }

      $logData = array(
         'action' => 'configureSwitchInterface',
         'object' => $searchSwitch . '/' . $switchInterface,
         'data' => array('old' => $currentIfConfig, 'new' => $configData),
         'status' => array('success' => $success, 'errors' => $errors),
      );

      $func->logchange($db, json_encode($logData));
   } else {
      print $html->info_message("No configuration change detected", "RESULT");
   }
}

function getAuthCmdsV1($configData, $searchSwitch)
{

   global $global;

   $switches = $global['switches'];

   $switchMode = $switches[$searchSwitch]['mode'];

   $return = array();

   if (!preg_match('/monitor|transition/i', $switchMode))
      $return[] = 'ip access-group ACL-DEFAULT in ';

   $return[] = 'authentication event fail action next-method';

   if (!$configData['voiceVlan']) {
      $return[] = 'authentication host-mode single-host';
   } else {
      // only for data + voice ports
      $return[] = 'authentication event server dead action authorize voice';
      $return[] = 'authentication host-mode multi-domain';
   }

   $return[] = 'authentication event server alive action reinitialize';
   $return[] = 'authentication event server dead action reinitialize vlan  ' . $configData['vlan'];

   $return[] = 'authentication open';
   $return[] = 'authentication order dot1x mab';
   $return[] = 'authentication priority dot1x mab';
   $return[] = 'authentication port-control auto';
   $return[] = 'authentication periodic';
   $return[] = 'authentication timer reauthenticate server';
   $return[] = 'authentication timer inactivity server';
   $return[] = 'authentication violation restrict';
   $return[] = 'mab';
   $return[] = 'mls qos trust dscp';
   $return[] = 'dot1x pae authenticator';
   $return[] = 'dot1x timeout tx-period 10';

   return $return;
}

function getAuthCmdsV2($configData, $currentIfConfig, $searchSwitch)
{

   global $global;

   $switches = $global['switches'];
   $userinfo = $global['userinfo'];
   $func = $global['func'];

   $switchMode = $switches[$searchSwitch]['mode'];

   $return = array();

   if (!preg_match('/monitor|transition/i', $switchMode))
      $return[] = 'ip access-group ACL-DEFAULT in ';

   $return[] = 'authentication event fail action next-method';

   if (!$configData['voiceVlan']) {
      $return[] = 'authentication host-mode single-host';
   } else {
      // only for data + voice ports
      $return[] = 'authentication event server dead action authorize voice';
      $return[] = 'authentication host-mode multi-domain';
   }

   $return[] = 'authentication periodic';
   $return[] = 'authentication timer reauthenticate server';
   $return[] = 'authentication port-control auto';
   $return[] = 'mab';
   $return[] = 'mls qos trust dscp';
   $return[] = 'dot1x pae authenticator';
   $return[] = 'dot1x timeout tx-period 10';

   // exclude the policy for management vlans 
   if ($configData['vlan'] != 2 && $configData['vlan'] != 3) {
      // Tear down policy map on old VLAN before applying policy map on new VLAN.
      $return[] = "no service-policy type control subscriber MANAGED_LAN_DEFAULT_DOT1X_POLICY_MAP_" . $currentIfConfig['ifvlan'];
      $return[] = "service-policy type control subscriber MANAGED_LAN_DEFAULT_DOT1X_POLICY_MAP_" . $configData['vlan'];
   }

   return $return;
}

function getPolicyMapCmds($vlanId, $ibns)
{

   global $global;

   $return[] = "policy-map type control subscriber MANAGED_LAN_DEFAULT_DOT1X_POLICY_MAP_" . $vlanId;
   $return[] = "event session-started match-all";
   $return[] = "10 class always do-until-failure";
   $return[] = "10 authenticate using dot1x retries 2 retry-time 0 priority 10";
   $return[] = "event authentication-failure match-first";
   $return[] = "5 class DOT1X_FAILED do-until-failure";
   $return[] = "10 terminate dot1x";
   $return[] = "20 authenticate using mab priority 20";
   $return[] = "10 class AAA_SVR_DOWN_UNAUTHD_HOST do-until-failure";
   $return[] = "10 clear-authenticated-data-hosts-on-port";
   $return[] = "20 activate service-template MANAGED_LAN_DEFAULT_CRITICAL_AUTH_" . $vlanId;
   $return[] = "30 activate service-template MANAGED_LAN_DEFAULT_CRITICAL_VOICE_TEMPLATE";
   $return[] = "40 authorize";
   $return[] = "50 pause reauthentication";
   $return[] = "20 class AAA_SVR_DOWN_AUTHD_HOST do-until-failure";
   $return[] = "10 pause reauthentication";
   $return[] = "20 authorize";
   $return[] = "30 class DOT1X_NO_RESP do-until-failure";
   $return[] = "10 terminate dot1x";
   $return[] = "20 authenticate using mab priority 20";
   $return[] = "40 class MAB_FAILED do-until-failure";
   $return[] = "10 terminate mab";
   $return[] = "20 authentication-restart 60";
   $return[] = "60 class DOT1X_TIMEOUT do-until-failure";
   $return[] = "10 terminate dot1x";
   $return[] = "20 authenticate using mab priority 20";
   $return[] = "70 class always do-until-failure";
   $return[] = "10 terminate dot1x";
   $return[] = "20 terminate mab";
   $return[] = "30 authentication-restart 60";
   $return[] = "event agent-found match-all";
   $return[] = "10 class always do-until-failure";
   $return[] = "10 terminate mab";
   $return[] = "20 authenticate using dot1x retries 2 retry-time 0 priority 10";
   $return[] = "event inactivity-timeout match-all";
   $return[] = "10 class always do-until-failure";
   $return[] = "10 clear-session";
   $return[] = "event authentication-success match-all";
   $return[] = "10 class always do-until-failure";
   $return[] = "10 activate service-template DEFAULT_LINKSEC_POLICY_SHOULD_SECURE";
   $return[] = "event violation match-all";
   $return[] = "10 class always do-until-failure";
   $return[] = "10 restrict";

   return $return;
}

function configurePortChannel()
{

   global $global;

   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $html = $global['html'];
   $config = $global['config'];
   $func = $global['func'];

   $switches = $global['switches'];
   $searchSwitch = $global['searchSwitch'];
   $switchInterface = $global['switchInterface'];
   $currentIfConfig = $global['currentIfConfig'];
   $selectIfConfig = $global['selectIfConfig'];

   $devip = $switches[$searchSwitch]['ipaddress'];

   $trunkVlans = $input->get('trunkvlans', 'all');

   $removeVlan = array_diff($currentIfConfig['iftrunkvlan'], $trunkVlans);
   $addVlan = array_diff($trunkVlans, $currentIfConfig['iftrunkvlan']);

   if ($removeVlan != $addVlan && $trunkVlans) {

      $command = 'switchport trunk allowed vlan ' . implode(',', $trunkVlans);

      $func->loadstart("Connecting to $searchSwitch ($devip), please wait ...", $searchSwitch);

      $connected = $ssh->connect($devip, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);

      if (!$connected) {
         $errors[] = "Could not SSH to device $searchSwitch ($devip)";
         $func->loadfinish($searchSwitch);
      } else {
         $ssh->command("terminal length 0");
         $ssh->command("configure terminal");
         $ssh->command("file prompt quiet");

         // Enter in config interface mode
         $ssh->command("interface " . $switchInterface);
         $ssh->command($command);
         $ssh->command("end");
         $ssh->command("copy running-config startup-config");
         $ssh->command("conf t");
         $ssh->command("file prompt alert");
         $ssh->command("end");

         $func->loadfinish($searchSwitch);

         $success[] = "Interface $switchInterface has been configured";

         $logData = array(
            'action' => 'configureSwitchInterface',
            'object' => $searchSwitch . '/' . $switchInterface,
            'data' => array('addVlan' => $addVlan, 'removeVlan' => $removeVlan),
            'status' => array('success' => $success, 'errors' => $errors),
         );

         $func->logchange($db, json_encode($logData));
         $func->loguser($db, $searchSwitch, "change.switchport");
      }
   } else {
      $success[] = "No change detected";
   }

   if ($success) {
      print $html->success_message($success, 'SUCCESS');
   }
   if ($errors) {
      print $html->error_message($errors, 'ERROR');
   }

}

function sectionSwitches()
{
   global $global;

   $func = $global['func'];
   $input = $global['input'];
   $db = $global['db'];
   $html = $global['html'];
   $tab = $global['tab'];
   $modal = $global['modal'];
   $submit = $global['submit'];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $permission = $global['permission'];
   $permissionList = $global['permissionList'];
   $siteSection = $global['siteSection'];
   $selectLocation = $global['selectLocation'];
   $searchSite = $global['searchSite'];

   $db_req_str = "SELECT * FROM user WHERE user_name = '{$username_str}'";
   $db_res_arr = $db->query($db_req_str);

   $preferences_arr = [];

   foreach ($db_res_arr as $value_arr) {
      $local_preferences_arr = json_decode($value_arr["preferences"], true);
      $preferences_arr = $local_preferences_arr[$tool_name_str]["favorites"]["device"];
   }

   $columns = array(
      'Select to Update Banner' => 'checkbox',
      "Favorite" => "favorite",
      'Reachable' => 'enabled',
      'Hostname' => 'name',
      'IP Address' => 'ipaddress',
      'Mode' => 'mode',
      'Switch in NAC' => 'nac',
      'Serial Number' => 'sn',
      'Uptime' => 'uptime',
      'OS Version' => 'osversion',
      'SysLocation' => 'sysLocation',
   );

   $topts = array('datatable' => 1, 'table.id' => 'switches');

   if ($searchSite) {
      $query = "SELECT primeid, name, ipaddress, mode, osversion,
                     COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(NULLIF(details, ''), '$.sysinfo.sn')), 'null'), '') as sn,
                     COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(NULLIF(details, ''), '$.sysinfo.sysLocation')), 'null'), '') as sysLocation,
                     COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(NULLIF(details, ''), '$.sysinfo.uptime')), 'null'), '') as uptime,
                     updated, enabled, nac, site
                     FROM lan_switch WHERE site LIKE '$searchSite' AND deleted = 0";
      // $query = "SELECT primeid, name,ipaddress,mode,osversion, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sn')) as sn,
      //                JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation,
      //                JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.uptime')) as uptime, updated, enabled, nac,site  " .
      //    "FROM lan_switch WHERE site LIKE '$searchSite' AND deleted = 0 AND details != ''";

      $switches = $db->query($query);

      $switches = array_values($switches);

      foreach ($switches as $id => $switch) {
         $switchPurpose = array();
         // With new permission granularity we need to check in sys location for keys like EDU|DEV|MFG|CON
         preg_match_all("/MFG|DEV|EDU|CON/", $switch['sysLocation'], $switchPurpose);

         // We need to limit access to switches based on purpose
         if (preg_match('/env/i', $permission)) {
            if (!$switchPurpose[0]) {
               unset($switches[$id]);
               continue;
            }

            // This is just an extra security layer as  SWC|SWG|SWT switches should never be tagged with env values, except envcon with SWC 
            if (!preg_match("/envcon/i", implode("|", $permissionList))) {
               if (preg_match('/SWC|SWG|SWT/i', $switch['name'])) {
                  unset($switches[$id]);
                  continue;
               }
            } else {
               if (preg_match('/SWG|SWT/i', $switch['name'])) {
                  unset($switches[$id]);
                  continue;
               }
            }

            $checkperm = preg_replace("/env/", "", (implode("|", $permissionList)));
            // Check if permissions matehes with switch purpose

            if (!preg_match("/$checkperm/i", strtolower(implode(',', $switchPurpose[0])))) {
               unset($switches[$id]);
               $unsetIds[] = $id;
               continue;
            }

         }
         // we need to know which switches need to be ignored
         if (in_array($id, $unsetIds)) {
            continue;
         }

         $switches[$id]['sn'] = preg_replace('/, /', '<br>', $switch['sn']);

         $switches[$id]['name'] = ($switch['enabled']) ? "<a href='/lan-manager.php?tab=Switch&searchSwitch=" . $switch['name'] . "'>" . $switch['name'] . "</a>" : $switch['name'];

         if ($switch['enabled']) {
            $switches[$id]['enabled'] = '<a class="ui green empty circular label"></a>';

            if ($switch['sysLocation'] && preg_match('/fulladmin/i', $permission)) {
               $switches[$id]['checkbox'] = $html->checkbox('switch_' . $id, $switch['ipaddress']);
            }
         } else {
            $switches[$id]['enabled'] = '<a class="ui red empty circular label"></a>';
         }

         if ($switch['nac']) {
            $switches[$id]['nac'] = '<i class="green checkmark icon"></i>';
         } else {
            $switches[$id]['nac'] = '<i class="red attention icon"></i>';
         }

         $hostname_str = $switch["name"];

         $favorite_html_str = "<div style='display: flex; justify-content: center; align-items:center;'>"; {
            $make_device_favorite_base_hyperlink_str = $global["make_device_favorite_base_hyperlink_str"];

            $hyperlink_props_arr = [
               "level" => "level=device",
               "action" => "",
               "hostname" => "hostname={$hostname_str}",
               "location" => "searchSite={$switch['site']}",
            ];

            $icon_str = "";

            if (in_array(strtolower($hostname_str), $preferences_arr)) {
               $hyperlink_props_arr["action"] = "action=remove";
               $icon_str = "yellow star icon";
            } else {
               $hyperlink_props_arr["action"] = "action=add";
               $icon_str = "star outline icon";
            }

            $make_device_favorite_hyperlink_str = $make_device_favorite_base_hyperlink_str . "&" . implode("&", $hyperlink_props_arr);
            $favorite_html_str .= "<a style='color: inherit; text-decoration: none;' href='{$make_device_favorite_hyperlink_str}'><i class='{$icon_str}'></i></a>";
         }
         $favorite_html_str .= "</div>";

         $switches[$id]["favorite"] = $favorite_html_str;

         preg_match('/B[0-9].{2}/', $switch['sysLocation'], $match);

         $switches[$id]['sysLocation'] = $switch['sysLocation'];
         $bannerCode = $match[0];
         $switches[$id]['banner_code'] = $bannerCode ? $bannerCode : 'Not found';
      }

      if (preg_match('/fulladmin/i', $permission)) {
         print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
         print $html->hidden_text('tab', $tab);
         print $html->hidden_text('searchSite', $searchSite);
         print $html->hidden_text('siteSection', $siteSection);
         print $html->hidden_text('countSwitches', count($switches));
         print "<br>";
         print $html->submit('submit', 'Update Banners', array('size' => 'tiny', 'color' => 'blue'));
         print "<br><br>";
         print $html->table($switches, $columns, $topts);
         print $html->endform();
         print '<br><br>';
      } else {
         unset($columns['Select to Update Banner']);
         print $html->table($switches, $columns, $topts);
      }

      if (preg_match('/Update Banners/i', $submit))
         updateBannersModal();
   }
}

function updateBannersModal()
{

   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $tab = $global['tab'];
   $input = $global['input'];

   $siteSection = $global['siteSection'];
   $searchSite = $global['searchSite'];

   $countSwitches = $input->get('countSwitches', 'all');

   $devices = array();
   $banners = array();

   for ($i = 0; $i < $countSwitches; $i++) {
      $ip = $input->get('switch_' . $i, 'all');

      if (empty($ip))
         continue;

      $statement = "SELECT ipaddress, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation FROM lan_switch WHERE ipaddress = '$ip'";
      $queryResult = $db->query($statement);

      foreach ($queryResult as $key => $value) {
         preg_match('/B[0-9].{2}/', $value['sysLocation'], $match);

         if (empty($match[0]))
            $banners[] = 'Empty Banner';
         else
            $banners[] = $match[0];
      }

      $devices[] = $ip;
   }

   $devices = json_encode($devices);

   if (count(array_unique($banners)) != 1)
      $warning = "Different banners were found on selected switch(s) in $searchSite";
   if (in_array('Empty Banner', $banners))
      $warning = "No banner found in sysLocation on selected switch(s) in $searchSite";

   print $html->startmodal("Change banners for selected switches in location $searchSite", true);
   //true property brings a close icon next to the div box, but you can close it without the 'x' icon. 
   //It is just not as intuitive for somee poeple
   print $html->startmodalcontent();
   print '<br><br>';
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   !empty($warning) ? print $html->warning_message($warning, 'WARNING') : print $html->info_message('The option you see below is the current banner for switch(s)', 'Select a banner');
   ;
   print $html->select('location_banners', $selectBanners, $banner);
   print '<br><br>';
   print "<div class='cliresult' id='banner_content' style='overflow-y:auto; overflow-x:auto; height:200px;'></div>";
   print '<br><br>';
   print $html->hidden_text('searchSite', $searchSite);
   print $html->hidden_text('tab', $tab);
   print $html->hidden_text('siteSection', $siteSection);
   print $html->hidden_text('switches_ip', $devices);
   print $html->endmodalcontent();
   print $html->submit('submit', 'Cancel', array('size' => 'large', 'color' => 'red', 'align' => 'right floated'));
   print $html->submit('submit', 'Apply Banners', array('size' => 'large', 'color' => 'green', 'align' => 'right floated'));
   print "<br><br><br><br>";
   print $html->endform();
   print $html->endmodal();

}

function showPrettyArray($arr)
{
   print '<pre>' . print_r($arr, true) . '</pre>';
}

function connAndExec($currentIP, $chosenBannerCode, $currentBannerCode, $currentSysLocation, $newSysLocation, $searchSwitch)
{

   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $config = $global['config'];

   $searchSite = $global['searchSite'];

   $func->loadstart("Configuring Login Banner on  $searchSwitch - $currentIP, please wait ...", $currentIP);

   $conn = $ssh->connect($currentIP, '22', $config['lanmgmt.user'], $config['lanmgmt.pass']);

   if ($conn) {
      $statement = "SELECT banner_code, banner_content FROM lan_switch_banner WHERE banner_code = '$chosenBannerCode'";
      $queryResult = $db->query($statement);

      $change_banner = "banner login ^" . $queryResult[$chosenBannerCode]['banner_content'] . "^";
      $change_sysLocation = "snmp-server location $newSysLocation";

      $ssh->command("conf t", 1);
      $ssh->command("file prompt quiet");
      $ssh->command($change_banner, 1);
      $ssh->command($change_sysLocation, 1);
      $ssh->command("end", 1);
      $ssh->command("copy running-config startup-config");
      $ssh->command("conf t");
      $ssh->command("file prompt alert");
      $ssh->command("end");

      $ssh->disconnect();

      $func->loadfinish($currentIP);

      $successMsg = "Banner Update was succesful for $searchSwitch - $currentIP";

      $statement = "SELECT name, details FROM lan_switch WHERE name LIKE '%$searchSwitch%'";
      $queryResult = $db->query($statement);
      $oldDetails = $queryResult[$searchSwitch]['details'];
      $newDetails = str_replace($currentSysLocation, $newSysLocation, $oldDetails);
      $statement = "UPDATE lan_switch SET details = '$newDetails' WHERE name LIKE '$searchSwitch'";
      $updateResult = $db->execute($statement);
   } else {
      $func->loadfinish($currentIP);
      $errorMsg = "Could not connect to $searchSwitch - $currentIP";
   }

   $logData = array(
      'action' => 'UpdateBanner',
      'object' => $searchSwitch,
      'data' => array('old' => $currentBannerCode, 'new' => $chosenBannerCode),
      'status' => array('success' => $successMsg, 'errors' => $errorMsg),
   );

   $func->logchange($db, json_encode($logData));

   return $logData['status'];
}

function applyBanners()
{

   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $db = $global['db'];
   $ssh = $global['ssh'];
   $config = $global['config'];

   $searchSite = $global['searchSite'];
   $switchesIP = $input->get('switches_ip', 'all');
   $switchesIP = json_decode($switchesIP, true);

   $chosenBannerCode = $input->get('location_banners', 'all');

   if (empty($switchesIP)) {
      print $html->error_message('No switch selected', 'ERROR');
      return 0;
   }

   if (preg_match('/Select/i', $chosenBannerCode)) {
      print $html->error_message('Invalid banner choice', 'ERROR');
      return 0;
   }

   for ($i = 0; $i < count($switchesIP); $i++) {
      if (empty($switchesIP[$i])) {
         continue;
      }

      $currentIP = $switchesIP[$i];
      $newSysLocation = '';

      $statement = "SELECT ipaddress, name, JSON_UNQUOTE(JSON_EXTRACT(details, '$.sysinfo.sysLocation')) as sysLocation FROM lan_switch WHERE ipaddress='$currentIP'";
      $queryResult = $db->query($statement);

      $currentSysLocation = $queryResult[$currentIP]['sysLocation'];
      $searchSwitch = $queryResult[$currentIP]['name'];

      if (preg_match('/B[0-9].{2}/', $currentSysLocation)) {
         preg_match('/B[0-9].{2}/', $currentSysLocation, $match);
         $currentBannerCode = $match[0];
         $newSysLocation = str_replace($currentBannerCode, $chosenBannerCode, $currentSysLocation);
      } else if (preg_match("/S[0-9]+,[' ']+L[0-9]+/", $currentSysLocation)) {
         $exploded = explode(',', $currentSysLocation);
         $newPart = trim($exploded[0]) . ', ' . trim($exploded[1]) . ', ' . $chosenBannerCode . ',';
         $newSysLocation = preg_replace("/S[0-9]+,[' ']+L[0-9]+([',',[' '])+/", $newPart, $currentSysLocation);
      }

      if (!preg_match("/S[0-9]+,[' ']+L[0-9]+,[' '].+" . $chosenBannerCode . "/", $newSysLocation)) {
         $errors[] = "Banner Update failed for $searchSwitch - $currentIP due to invalid SysLocation $newSysLocation";
         continue;
      }

      $result = connAndExec($currentIP, $chosenBannerCode, $currentBannerCode, $currentSysLocation, $newSysLocation, $searchSwitch);
      if ($result['success']) {
         $success[] = $result['success'];
      }
      if ($result['errors']) {
         $errors[] = $result['errors'];
      }
   }

   if ($success) {
      print $html->success_message($success, "SUCCESS");
   }
   if ($errors) {
      print $html->error_message($errors, "ERROR");
   }

}


/**
 * @param array $results
 * @param string $permission
 * @param array $allowedSwitches
 * @return array
 */
function getFilteredEndpoints($results, $permission, $allowedSwitches)
{
   global $func;

   $endpoints = [];

   if (preg_match('/envcon/i', $permission)) {
      foreach ($results as $id => $result) {
         foreach ($allowedSwitches as $ip => $allowedSwitch) {
            if (preg_match('/' . $result['switch_name'] . '/i', $allowedSwitch) && !preg_match('/L\d{5}$/i', $result['switch_name'])) {
               $endpoints[$id] = $result;
            }

            if (preg_match('/L\d{5}$/i', $result['switch_name'])) {
               if ($ip == $result['switch_ip']) {
                  $endpoints[$id] = $result;
               }
            }
         }
      }
   } else {
      $endpoints = array_values($results);
   }

   return $endpoints;
}

function sectionCategorize()
{

   global $global;

   $input = $global['input'];
   $debug = $global['debug'];
   $db = $global['db'];
   $html = $global['html'];
   $modal = $global['modal'];
   $submit = $global['submit'];
   $userinfo = $global['userinfo'];
   $normal_user_name = $global['normal_user_name'];
   $func = $global["func"];
   $allowedSwitches = $global['allowedSwitches'];

   $endpoint_str = $global["endpoint"];

   $searchSite = $global['searchSite'];

   $permission = $global['permission'];

   $columns = array(
      'Endpoint' => 'endpoint',
      'Profile' => 'endpoint_profile',
      'Switch' => 'switch_name',
      'Switch IP' => 'switch_ip',
      'Interface' => 'switch_port',
      'Vlan' => 'ifvlan',
      'Port Description' => 'description',
      'Last Seen' => 'seen',
      'Timestamp' => 'last_seen',
      'Action' => 'action',
      'Status' => 'review_status',
      'ADM Account' => 'review_adm_account'
   );

   $topts = array('datatable' => $endpoint_str ? 0 : 1, 'table.id' => 'categorize');

   if ($searchSite) {
      $user_name = empty($normal_user_name) ? $userinfo['username'] : $normal_user_name;

      echo "<div style='margin-top: 10px;'>";
      echo "<div id='endpoint_alert'></div>";
      echo "<script type='text/javascript' src='./js/new-end-point-alert.js?v=15'></script>";
      echo "<script>
         const container = document.getElementById('endpoint_alert');

         if (container){
            new NewEndPointAlert({
               target: container,
               props : {
                  user_name     : '$user_name',
                  location_code : '$searchSite'
               }
            })
         }
         </script>";
      echo "</div>";

      $swQuery = 'SELECT  ipaddress, name, site, region, ipaddress, ' .
         'JSON_UNQUOTE(JSON_EXTRACT(details, "$.interfaces")) as interfaces ' .
         'FROM lan_switch ' .
         "WHERE site LIKE '$searchSite'";

      $siteSwitches = $db->query($swQuery);

      foreach ($siteSwitches as $siteSwitch => $details) {
         $siteSwitches[$siteSwitch]['interfaces'] = json_decode($details['interfaces'], true);
      }

      $query = "SELECT * FROM lan_switch_nac WHERE switch_name LIKE '$searchSite%' AND authentication LIKE 'failed' " .
         "AND authorization not like '%permanent%' AND authorization not like '%temp%' " .
         "ORDER BY last_seen DESC";


      $results = $db->query($query);

      foreach ($results as $id => $result) {
         $endpoint = $result['endpoint'];

         if (preg_match('/basic|full|envcon/i', $permission)) {
            $results[$id]['action'] = "<a href='lan-manager.php?tab=Site&siteSection=Categorize&" .
               "searchSite=$searchSite&modal=Authorize&endpoint=" . $result['endpoint'] . "'>Authorize</a>";

            $results[$id]['action'] .= ' | ';

            $results[$id]['action'] .= "<a href='lan-manager.php?tab=Site&siteSection=categorize&searchSite=$searchSite&modal=ReviewEndpoint&endpoint={$result['endpoint']}'>Review</a>";
         }

         $results[$id]['endpoint'] = "<a href='/lan-manager.php?tab=Site&siteSection=Categorize&" .
            "searchSite=$searchSite&modal=History&endpoint=" . $endpoint . "' " .
            "data-toggle='tooltip' title='Click to see history'>$endpoint</a>";

         $current_time = time();
         $date_diff = $current_time - strtotime($results[$id]['last_seen']);
         $days_ago = round($date_diff / (60 * 60 * 24));
         $seen = "{$days_ago} days ago";

         $results[$id]['seen'] = $seen;
         $results[$id]['review_status'] = ucfirst(strtolower($result['review_status']));
         $results[$id]['review_adm_account'] = $result['review_adm_account'];

         // Mapping the switch port from nac logs to interface desc 
         foreach ($siteSwitches[$result['switch_ip']]['interfaces'] as $interface) {
            if ($interface['ifname'] == $result['switch_port']) {
               $results[$id]['description'] = $interface['ifdesc'];
               $results[$id]['ifvlan'] = $interface['ifvlan'];
               break;
            }
         }
      }

      $endpoints = getFilteredEndpoints($results, $permission, $allowedSwitches);

      unset($results);

      echo "<div style='margin-top: 15px; margin-bottom: 15px;'>";
      echo "<a href='lan-manager.php?tab=Site&siteSection=categorize&searchSite=$searchSite&modal=SubscriptionList'><i class='list icon'></i> Subscription List</a>";
      echo "</div>";

      $quick_tips = [
         'To easily view the remarks and history without using the "Review" functionality under "Action" column , just click on the mac address in blue under "Endpoint" column.',
         'To filter endpoints by the number of last seen days, the query has to be in quotes, e.g. for 6 days ago endpoints type "6 days ago", remember it to put in double quotes'
      ];

      echo "<div style='margin-top: 10px; margin-bottom: 10px;'>";
      echo $html->info_message($quick_tips, 'Quick Tips for Endpoint Table interactions');
      echo "</div>";

      if (preg_match('/Authorize/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
         showAuthorizeModal();
      }
      if (preg_match('/History/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
         showEndpointHistoryModal();
      }

      if (preg_match('/ReviewEndpoint/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
         showReviewEndpointModal();
      }

      if (preg_match('/SubscriptionList/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
         showSubscriptionListModal();
      }

      print $html->table($endpoints, $columns, $topts);

      if ($endpoint_str) {
         echo "<script>"; {

            echo "
            const table = $('#categorize').DataTable();
      
            console.log(table);
      
            table.search('{$endpoint_str}').draw();
           ";

         }
         echo "</script>";
      }
   }
}

function showSubscriptionListModal()
{
   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $db = $global['db'];
   $searchSite = $global['searchSite'];
   $adbasedn = $global['adbasedn'];
   $ldap = $global['ldap'];

   $sql_req = "SELECT id, alert_list FROM lan_switch WHERE name LIKE '%$searchSite%' LIMIT 1";
   $sql_res = $db->query($sql_req);

   $columns = [
      'Full Name' => 'Full Name',
      'User' => 'User',
      'Subscribed Since' => 'Subscribed Since'
   ];

   foreach ($sql_res as $id => $props) {
      $alert_list = json_decode($props['alert_list'], true);

      foreach ($alert_list as $user => $subscribed_since) {
         $filter = "(sAMAccountName=" . $user . ")";
         $attr = ["displayName"];
         $ldap_res = $ldap->search($adbasedn, $filter, $attr);
         $full_name = !empty($ldap_res[0]['displayname'][0]) ? $ldap_res[0]['displayname'][0] : "Name not found";

         $table_data[] = [
            'Full Name' => $full_name,
            'User' => $user,
            'Subscribed Since' => $subscribed_since
         ];
      }
   }

   $topts = array('datatable' => 0, 'table.id' => 'test_table');

   echo $html->startmodal("Subscription List for $searchSite", true);
   echo $html->startmodalcontent();
   print "<br><br><br><br>";
   print $html->table($table_data, $columns, $topts);
   print "<br><br><br><br>";
   echo $html->endmodalcontent();
   echo $html->endmodal();
}

function showReviewEndpointModal()
{
   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $endpoint = $global['endpoint'];
   $permission = $global['permission'];
   $userinfo = $global['userinfo'];
   $db = $global['db'];
   $searchSite = $global['searchSite'];

   $date = date('Y-m-d h:m', time());
   $endpoint_statuses = ['Investigating', 'Reviewed'];

   $sql_req = "SELECT review_status, remark FROM lan_switch_nac WHERE endpoint ='$endpoint'";
   $sql_res = $db->query($sql_req);

   foreach ($sql_res as $key => $res) {
      if (preg_match('/new/i', $key)) {
         $new_endpoint = true;
      }

      $remark = $res['remark'];
      $endpoint_status = $res['review_status'];
   }

   print $html->startmodal("Endpoint Review for $endpoint", true);
   print $html->startmodalcontent();

   if (!preg_match('/basic|full|envcon/i', $permission)) {
      print "<div class='ui one column centered grid'>";
      print "<div class='column'>";
      print $html->error_message("Your current role and assigned permission do not permit access to this feature", "UnauthorizedAccess");
      print "</div>";
      print "</div>";
   } else {
      print "<div class='ui one column centered grid'>";

      if ($new_endpoint == true) {
         $endpoint_status = 'Investigating';

         print "<div class='column'>";
         print $html->info_message("For new Endpoints, 'Investigating' will be pre-selected as current status. The status can be changed at any time.", "New Endpoint");
         print "</div>";
      }

      print "<div class='column' style='width: 800px;'>";
      print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
      print "<div class='ui two column grid'>";
      print "<div class='row'>";
      print "<div class='column'>";
      print "<div class='ui labeled input'>
                                    <div class='ui label' style='margin-left:20px; width: 70px;'>
                                       Status
                                    </div>";
      print $html->select('endpoint_status_dropdown', $endpoint_statuses, $endpoint_status, ['style' => 'width: 148px; border-top-right-radius: 5px; border-bottom-right-radius: 5px;']);
      print "</div>";
      print "<br><br>";
      print "<div class='ui labeled input'>
                                    <div class='ui label' style='margin-left:20px; width: 70px;'>
                                       User
                                    </div>
                                    <input type='text' value='{$userinfo['username']}' readonly size=15>
                                 </div>";
      print "<br><br>";
      print "<div class='ui labeled input'>
                                    <div class='ui label' style='margin-left:20px; width: 70px;'>
                                       Date
                                    </div>
                                    <input type='text' value='$date' readonly size=15>
                                 </div>";
      print "</div>";
      print "<div class='column'>";
      print "<div class='ui form'>
                                    <div class='field' style='margin-right:20px;'>
                                       <label>Remarks (max. 150 characters)</label>" .
         $html->textarea('endpoint_remark', $remark, 10, 5, ['placeholder' => 'Leave your remark for current endpoint in this field.', 'maxlength' => 150])
         . "</div>
                                 </div>";
      print "</div>";
      print "</div>";
      print "</div>";
      print "<br><br>";
      print $html->hidden_text('siteSection', 'Categorize');
      print $html->hidden_text('tab', 'Site');
      print $html->hidden_text('searchSite', $searchSite);
      print $html->hidden_text('endpoint_timestamp', $date);
      print $html->hidden_text('endpoint', $endpoint);
      print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'red', 'align' => 'right floated'));
      print $html->submit('submit', 'Save', array('size' => 'tiny', 'color' => 'green', 'align' => 'right floated'));
      print $html->endform();
      print "</div>";
      print "</div>";
   }

   print $html->endmodalcontent();
   print $html->endmodal();
}

function reviewEndpoint()
{
   global $global;

   $html = $global['html'];
   $func = $global['func'];
   $endpoint = $global['endpoint'];
   $userinfo = $global['userinfo'];
   $db = $global['db'];
   $input = $global['input'];
   $permission = $global['permission'];

   if (!preg_match('/basic|full|envcon/i', $permission)) {
      print "<div class='ui one column centered grid'>";
      print "<div class='column'>";
      print $html->error_message("Your current role and assigned permission do not permit access to this feature", "UnauthorizedAccess");
      print "</div>";
      print "</div>";

      return 0;
   }

   $endpoint_status = $input->get('endpoint_status_dropdown', 'alpha');
   $endpoint_remark = $input->get('endpoint_remark', 'all');
   $endpoint_timestamp = $input->get('endpoint_timestamp', 'all');

   $sql_req = "UPDATE lan_switch_nac SET remark = '$endpoint_remark', review_status = '$endpoint_status', review_adm_account = '{$userinfo['username']}' WHERE endpoint = '$endpoint'";
   $sql_res = $db->execute($sql_req);

   if (!empty($sql_res)) {
      $success = "$endpoint's status has been updated.";

      echo $html->success_message($success, 'Success');
   } else {
      $error = "$endpoint's status has not been updated.";

      echo $html->success_message($error, 'Error');
   }

   $log_data = [
      'action' => 'updateRemarks',
      'object' => $endpoint,
      'data' => ['remark' => $endpoint_remark, 'status' => $endpoint_status],
      'status' => ['success' => $success, 'error' => $error]
   ];

   $func->logchange($db, json_encode($log_data));
}

function showEndpointHistoryModal()
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $func = $global['func'];

   $tab = $global['tab'];
   $siteSection = $global['siteSection'];
   $searchSite = $global['searchSite'];
   $endpoint = $global['endpoint'];

   $query = "SELECT tool_log_date as Updated, 
            tool_log_user as 'Updated By', 
            JSON_UNQUOTE(json_extract(tool_log_desc,'$.action')) as Action 
            FROM tool_changelog 
            WHERE tool_log_tool like 'lan.manager' AND tool_log_desc LIKE '%$endpoint%' ORDER BY tool_log_date desc";

   $history = $db->query($query);

   print $html->startmodal("History for $endpoint", true);

   print $html->startmodalcontent();

   print "<div class='ui one column centered grid'>";
   print "<div class='column'>";

   $sql_req = "SELECT id, remark FROM lan_switch_nac WHERE endpoint = '$endpoint'";
   $sql_res = $db->query($sql_req);

   foreach ($sql_res as $id => $props) {
      $remark = $props['remark'];
   }

   echo "<div class='ui form'>
               <div class='field' style='margin-right:0px; margin-bottom: 10px;'>
                  <label>Remarks (max. 150 characters)</label>" .
      $html->textarea('endpoint_remark', $remark, 10, 5, ['placeholder' => 'Leave your remark for current endpoint in this field.', 'maxlength' => 150, 'readonly' => true])
      . "</div>
         </div>";

   if ($history) {
      print $html->table($history, array(), array('datatable' => 1, 'table.id' => 'history'));
   } else {
      print $html->info_message("Could not find any history related to this endpoint", "RESULT");
   }

   print "</div>";
   print "</div>";

   print $html->endmodalcontent();

   print $html->endmodal();

}

function showAuthorizeModal()
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];

   $endpoint = $global['endpoint'];
   $searchMac = $global['searchMac'];

   $tab = $global['tab'];

   $siteSection = $global['siteSection'];
   $searchSite = $global['searchSite'];

   $authGroups = $global['authGroups'];

   $selectLocation = $global['selectLocation'];

   $permission = $global['permission'];

   if (!$endpoint) {
      $endpoint = $searchMac;
   }

   // Detect the location if the endpoint is already in DB 
   $query = "SELECT endpoint,switch_name FROM lan_switch_nac WHERE endpoint LIKE '$endpoint'";

   $endpointDetails = $db->query($query);
   $authLocation = substr($endpointDetails[$endpoint]['switch_name'], 0, 8);

   print $html->startmodal("Authorize $endpoint", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   print "<div class='sixteen wide column'>";
   print "<br>";

   if ($searchMac) {
      print $html->info_message('Endpoint not present in ISE / Not authorized', 'NOTE');
      print "<br><br>";
   }

   print "Authorization Type";
   print "<br><br>";
   print $html->select('authGroup', $authGroups);
   print "<br><br>";
   print "Location ";
   print "<br><br>";
   print $html->select(
      'authLocation',
      $selectLocation,
      $authLocation,
      array('class' => 'ui fluid search selection dropdown')
   );
   print "<br><br>";
   print "Description";
   print "<br><br>";
   print $html->input_text('authDesc', $authDesc, 50, 50);
   print "<br><br>";
   print "IP Phone &nbsp&nbsp";
   print $html->checkbox('authPhone');
   print "</div>";

   print $html->endmodalcontent();

   print "<br><br><br>";

   print $html->hidden_text('tab', 'Site');
   print $html->hidden_text('siteSection', 'LifeCycle');
   print $html->hidden_text('endpoint', $endpoint);
   print $html->hidden_text('searchMac', $searchMac);
   print $html->hidden_text('searchSite', $searchSite);

   if (preg_match('/basic|full|envcon/i', $permission)) {
      print $html->submit('submit', 'Authorize', array('size' => 'tiny', 'color' => 'green', 'align' => 'right floated'));
   }

   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();

}

function authorizeEndpoint()
{

   global $global;

   $db = $global['db'];
   $func = $global['func'];
   $html = $global['html'];
   $ise = $global['ise'];

   $userinfo = $global['userinfo'];

   $isedevs = $global['isedevs'];

   $endpoint = $global['endpoint'];
   $searchMac = $global['searchMac'];

   $authDetails = $global['authDetails'];
   $authGroups = $global['authGroups'];

   $func->loadstart("Loading, please wait...");

   if (!$authDetails['authLocation']) {
      print $html->error_message('Location is mandatory when authorizing an endpoint.', 'ERROR');
      return;
   }

   foreach ($isedevs['radius'] as $isedev) {
      if (preg_match('/^cn|china/i', $authDetails['authLocation'])) {
         continue;
      }

      $endpointGrp = $ise->getEndpointIdentityGroupbyName($isedev['name'], $authDetails['authGroup']);
      $groupId = $endpointGrp['EndPointGroup']['id'];

      $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

      // If endpoint exists in ise
      if ($endpointDetails[$isedev['name']]['ERSEndPoint']['name']) {
         // Only put the mac in the group 
         $data = $endpointDetails[$isedev['name']];
         //$data['ERSEndPoint']['profileId'] = $groupId;
         $data['ERSEndPoint']['groupId'] = $groupId;
         $data['ERSEndPoint']['staticGroupAssignment'] = true;

         if ($authDetails['authPhone']) {
            $data['ERSEndPoint']['customAttributes'] = array(
               "customAttributes" => array(
                  "VoiceDomain" => "true",
                  "iPSK" => ""
               ),
            );

         }

         $updateEndpoint = $ise->updateEndpoint($isedev['name'], $data, $endpointDetails[$isedev['name']]['ERSEndPoint']['id']);

         if (preg_match('/success/i', $updateEndpoint)) {
            $success[] = "Endpoint <b>$endpoint</b> has been authorized in ISE " . $isedev['name'];
         } else {
            $errors[] = "Could not authorize device in ISE " . $isedev['name'];
         }
      } else {
         // create Mac 
         $data['ERSEndPoint'] = array(
            'name' => $endpoint,
            'description' => $authDetails['authLocation'],
            'mac' => $endpoint,
            //'profileId' => $groupId,
            //'staticProfileAssignment' => false,
            'groupId' => $groupId,
            'staticGroupAssignment' => true,
         );

         if ($authDetails['authPhone']) {
            $data['ERSEndPoint']['customAttributes'] = array(
               "customAttributes" => array(
                  "VoiceDomain" => "true",
                  "iPSK" => ""
               ),
            );

         }

         $createEndpoint = $ise->createEndpoint($isedev['name'], $data);

         if (preg_match('/success/', $createEndpoint)) {
            $success[] = "Endpoint <b>$endpoint</b> has been created and authorized in ISE " . $isedev['name'];
         } else {
            $errors[] = "Could not create/authorize device in ISE " . $isedev['name'];
         }
      }
   }

   // Do the same for radius.china if the location starts with CN or contains 'china'
   if (preg_match('/^cn|china/i', $authDetails['authLocation'])) {
      foreach ($isedevs['radius.china'] as $isedev) {

         $endpointGrp = $ise->getEndpointIdentityGroupbyName($isedev['name'], $authDetails['authGroup']);
         $groupId = $endpointGrp['EndPointGroup']['id'];

         $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

         // If endpoint exists in ise
         if ($endpointDetails[$isedev['name']]['ERSEndPoint']['name']) {
            // Only put the mac in the group 
            $data = $endpointDetails[$isedev['name']];
            //$data['ERSEndPoint']['profileId'] = $groupId;
            $data['ERSEndPoint']['groupId'] = $groupId;
            $data['ERSEndPoint']['staticGroupAssignment'] = true;

            if ($authDetails['authPhone']) {
               $data['ERSEndPoint']['customAttributes'] = array(
                  "customAttributes" => array(
                     "VoiceDomain" => "true",
                     "iPSK" => ""
                  ),
               );

            }

            $updateEndpoint = $ise->updateEndpoint($isedev['name'], $data, $endpointDetails[$isedev['name']]['ERSEndPoint']['id']);

            if (preg_match('/success/i', $updateEndpoint)) {
               $success[] = "Endpoint <b>$endpoint</b> has been authorized in ISE " . $isedev['name'];
            } else {
               $errors[] = "Could not authorize device in ISE " . $isedev['name'];
            }
         } else {
            // create Mac 
            $data['ERSEndPoint'] = array(
               'name' => $endpoint,
               'description' => $authDetails['authLocation'],
               'mac' => $endpoint,
               //'profileId' => $groupId,
               'staticProfileAssignment' => false,
               'groupId' => $groupId,
               'staticGroupAssignment' => true,
            );

            if ($authDetails['authPhone']) {
               $data['ERSEndPoint']['customAttributes'] = array(
                  "customAttributes" => array(
                     "VoiceDomain" => "true",
                     "iPSK" => ""
                  ),
               );

            }

            $createEndpoint = $ise->createEndpoint($isedev['name'], $data);

            if (preg_match('/success/', $createEndpoint)) {
               $success[] = "Endpoint <b>$endpoint</b> has been created and authorized in ISE " . $isedev['name'];
            } else {
               $errors[] = "Could not create/authorize device in ISE " . $isedev['name'];
            }
         }
      }
   }
   // Update db
   $format = "('%s', '%s', '%s', %s, '%s', now(), '%s')";

   $authDetails['authDesc'] = $db->escape_string($authDetails['authDesc']);

   $values = sprintf(
      $format,
      $endpoint,
      $authDetails['authLocation'],
      $authGroups[$authDetails['authGroup']],
      $authDetails['authPhone'],
      $userinfo['username'],
      $authDetails['authDesc']
   );
   $insert = "INSERT INTO lan_switch_nac (endpoint, switch_name, authorization, ipphone, updated_by, updated, " .
      "description) " .
      "VALUES $values ON DUPLICATE KEY UPDATE authorization = VALUES(authorization), " .
      "switch_name=VALUES(switch_name), ipphone = VALUES(ipphone), " .
      "updated_by = VALUES(updated_by), updated = VALUES(updated), description = VALUES(description)";

   $inc = $db->execute($insert);

   if ($inc) {
      $success[] = "Record was updated in the database";
   } else {
      $errors[] = "Could not update database";
   }

   if ($success) {
      print $html->success_message($success, 'SUCCESS');
   }
   if ($errors) {
      print $html->error_message($errors, "ERROR");
   }

   $logData = array(
      'action' => 'authorizeEndpoint - ' . $authGroups[$authDetails['authGroup']],
      'object' => $endpoint,
      'data' => $data,
      'status' => array('success' => $success, 'errors' => $errors),
   );

   $func->logchange($db, json_encode($logData));
   $func->loadFinish();
}

function sectionLifeCycle()
{

   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $modal = $global['modal'];
   $func = $global['func'];
   $endpoint_str = $global["endpoint"];
   $allowedSwitches = $global['allowedSwitches'];
   $permission = $global['permission'];

   $searchSite = $global['searchSite'];

   $permission = $global['permission'];

   $columns = array(
      'Select to Delete' => 'checkbox',
      'Authorization' => 'authorization',
      'Endpoint' => 'endpoint',
      'Profile' => 'endpoint_profile',
      'IP Phone' => 'ipphone',
      'Switch' => 'switch_name',
      'Switch IP' => 'switch_ip',
      'Interface' => 'switch_port',
      'Description' => 'description',
      'Updated' => 'updated',
      'Updated By' => 'updated_by',
      'Last Seen' => 'last_seen',
      'Action' => 'action',
   );

   $topts = array('datatable' => $endpoint_str ? 0 : 1, 'table.id' => 'lifecycle');

   if ($searchSite) {

      $query = "SELECT * FROM lan_switch_nac WHERE switch_name LIKE '$searchSite%' AND " .
         "(authorization LIKE '%permanent%' OR authorization LIKE '%temp%')" .
         "ORDER BY last_seen DESC";

      $results = $db->query($query);

      // reindex the array 
      $results = array_values($results);
      print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

      foreach ($results as $id => $result) {
         $endpoint = $result['endpoint'];

         $results[$id]['endpoint'] = "<a href='/lan-manager.php?tab=Site&siteSection=LifeCycle&" .
            "searchSite=$searchSite&modal=History&endpoint=" . $endpoint . "' " .
            "data-toggle='tooltip' title='Click to see history'>$endpoint</a>";
         $results[$id]['ipphone'] = ($result['ipphone']) ? 'YES' : 'NO';

         if (preg_match('/basic|full|envcon/i', $permission)) {
            $results[$id]['checkbox'] = $html->checkbox('deleteBulk_' . $id, $endpoint);
            $results[$id]['action'] = "<a href='/lan-manager.php?tab=Site&siteSection=LifeCycle&" .
               "searchSite=$searchSite&modal=DeleteAuthorization&endpoint=" . $result['endpoint'] . "'>Delete Authorization</a>";
         }
      }

      print $html->hidden_text('tab', 'Site');
      print $html->hidden_text('siteSection', 'LifeCycle');
      print $html->hidden_text('deleteBulkCount', count($results));
      print $html->hidden_text('searchSite', $searchSite);

      if (preg_match('/basic|full|envcon/i', $permission)) {
         print "<br>";
         print $html->submit('submit', 'Delete selected', array('color' => 'grey', 'size' => 'tiny'));
         print "<br><br>";
      }

      $endpoints = getFilteredEndpoints($results, $permission, $allowedSwitches);

      print $html->table($endpoints, $columns, $topts);

      print $html->endform();

      if (preg_match('/DeleteAuthorization/i', $modal) && !preg_match('/cancel|authorize/i', $submit)) {
         showDeleteAuthorizationModal();
      }
      if (preg_match('/History/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
         showEndpointHistoryModal();
      }

   }

   if ($endpoint_str) {
      echo "<script>"; {

         echo "
         const table = $('#lifecycle').DataTable();
   
         console.log(table);
   
         table.search('{$endpoint_str}').draw();
        ";

      }
      echo "</script>";
   }

}

function showDeleteAuthorizationModal()
{

   global $global;

   $html = $global['html'];
   $ise = $global['ise'];
   $isedevs = $global['isedevs'];

   $endpoint = $global['endpoint'];
   $searchMac = $global['searchMac'];
   $tab = $global['tab'];

   $siteSection = $global['siteSection'];
   $searchSite = $global['searchSite'];
   $locations = $global['locations'];

   $permission = $global['permission'];

   if (!$endpoint) {
      $endpoint = $searchMac;
   }

   $valid = 0;

   foreach ($isedevs['radius'] as $isedev) {
      $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

      // If the Endpoint exists in ISE
      if ($endpointDetails[$isedev['name']]['ERSEndPoint'])
         $valid++;

      $groupId = $endpointDetails[$isedev['name']]['ERSEndPoint']['groupId'];
      $locationCode = $endpointDetails[$isedev['name']]['ERSEndPoint']['description'];

      if ($groupId) {
         // Check what group the endpoint belongs to
         $endpointGrp = $ise->getEndpointIdentityGroup($isedev['name'], $groupId);
         $authGroup = $endpointGrp['EndPointGroup']['name'];
      }
   }

   foreach ($isedevs['radius.china'] as $isedev) {
      $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

      // If the Endpoint exists in ISE CHINA
      if ($endpointDetails[$isedev['name']]['ERSEndPoint'])
         $valid++;

      $groupId = $endpointDetails[$isedev['name']]['ERSEndPoint']['groupId'];
      $locationCode = $endpointDetails[$isedev['name']]['ERSEndPoint']['description'];

      if ($groupId) {
         // Check what group the endpoint belongs to
         $endpointGrp = $ise->getEndpointIdentityGroup($isedev['name'], $groupId);
         $authGroup = $endpointGrp['EndPointGroup']['name'];
      }
   }

   print $html->startmodal("Delete Authorization for $endpoint", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   if ($valid) {
      print "<div class='sixteen wide column'>";
      print "<p>";
      print "<br><br>";
      print "Site/Location: " . $locationCode . " (" . $locations[$locationCode]['location'] . ")";
      print $html->hidden_text('authLocation', $locationCode);
      print "<br><br>";
      print "Authorization Type: " . $authGroup;
      print "<br>";
      print "</div>";
      print "</p>";
   } else {
      print "<p>";
      print "<h4>Could not find endpoint $endpoint in ISE</h4>";
      print "</p>";
   }

   print $html->endmodalcontent();

   print $html->hidden_text('tab', 'Site');
   print $html->hidden_text('siteSection', $siteSection);
   print $html->hidden_text('endpoint', $endpoint);
   print $html->hidden_text('searchMac', $searchMac);
   print $html->hidden_text('searchSite', $searchSite);

   print "<br><br><br>";

   if ($valid && preg_match('/basic|full|envcon/i', $permission)) {
      print $html->submit(
         'submit',
         'Delete Authorization',
         array('size' => 'tiny', 'color' => 'red', 'align' => 'right floated')
      );
   }
   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();

}

function deleteAuthorization()
{

   global $global;

   $html = $global['html'];
   $func = $global['func'];

   $ise = $global['ise'];
   $db = $global['db'];

   $isedevs = $global['isedevs'];
   $endpoint = $global['endpoint'];

   $func->loadstart("Loading, please wait...");

   $sendcoa = $ise->sendCoa($isedevs['monitor'][0]['name'], $endpoint);

   foreach ($isedevs['radius'] as $isedev) {
      $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

      if ($endpointDetails[$isedev['name']]['ERSEndPoint']['id']) {

         $deleteEndpoint = $ise->deleteEndpoint($isedev['name'], $endpointDetails[$isedev['name']]['ERSEndPoint']['id']);

         if (preg_match('/success/i', $deleteEndpoint)) {
            $success[] = "Endpoint <b>$endpoint </b>has been removed from ISE " . $isedev['name'];
         } else {
            $errors[] = $deleteEndpoint['ERSResponse']['message'];
         }
      }
   }

   // Remove form China radius if applicable
   foreach ($isedevs['radius.china'] as $isedev) {
      $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

      if ($endpointDetails[$isedev['name']]['ERSEndPoint']['id']) {

         $deleteEndpoint = $ise->deleteEndpoint($isedev['name'], $endpointDetails[$isedev['name']]['ERSEndPoint']['id']);

         if (preg_match('/success/i', $deleteEndpoint)) {
            $success[] = "Endpoint <b>$endpoint </b>has been removed from ISE " . $isedev['name'];
         } else {
            $errors[] = $deleteEndpoint['ERSResponse']['message'];
         }
      }
   }

   if (!$errors && $endpoint) {

      $delete = "DELETE FROM lan_switch_nac WHERE endpoint LIKE '$endpoint'";

      $del = $db->execute($delete);

      if ($del) {
         $success[] = "Endpoint <b>$endpoint</b> has been deleted from database";
      } else {
         $errors[] = "Could not delete endpoint <b>$endpoint</b>  from database";
      }

   }

   if ($success) {
      print $html->success_message($success, "SUCCESS");
   }
   if ($errors) {
      print $html->error_message($errors, "ERROR");
   }

   if ($endpoint) {
      $logData = array(
         'action' => 'deleteAuthorization',
         'object' => $endpoint,
         'data' => array(),
         'status' => array('success' => $success, 'errors' => $errors),
      );

      $func->logchange($db, json_encode($logData));
   }

   $func->loadfinish();

}

function deleteAuthorizationBulk()
{

   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];
   $db = $global['db'];

   $isedevs = $global['isedevs'];
   $ise = $global['ise'];

   $count = $input->get('deleteBulkCount', 'numeric');

   $func->loadstart("Loading, please wait...");

   for ($i = 0; $i < $count; $i++) {
      $endpoint = $input->get('deleteBulk_' . $i, 'all');

      if ($endpoint) {

         $sendcoa = $ise->sendCoa($isedevs['monitor'][0]['name'], $endpoint);

         foreach ($isedevs['radius'] as $isedev) {

            $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

            if ($endpointDetails[$isedev['name']]['ERSEndPoint']['id']) {
               $deleteEndpoint = $ise->deleteEndpoint(
                  $isedev['name'],
                  $endpointDetails[$isedev['name']]['ERSEndPoint']['id']
               );

               if (preg_match('/success/i', $deleteEndpoint)) {
                  // Keep the list of deleted enapoints for logging
                  $endpoints[$endpoint] = $endpoint;

                  $success[] = "Endpoint <b>$endpoint </b>has been removed from ISE " . $isedev['name'];

                  $delete = "DELETE FROM lan_switch_nac WHERE endpoint LIKE '$endpoint'";

                  $del = $db->execute($delete);

                  if ($del) {
                     $success[] = "Endpoint <b>$endpoint</b> has been deleted from database";
                  } else {
                     $errors[] = "Could not delete endpoint <b>$endpoint</b> from database";
                  }
               } else {
                  $errors[] = $deleteEndpoint['ERSResponse']['message'];
               }
            }
         }

         // Remove form China radius if applicable
         foreach ($isedevs['radius.china'] as $isedev) {

            $endpointDetails[$isedev['name']] = $ise->getEndpointByName($isedev['name'], $endpoint);

            if ($endpointDetails[$isedev['name']]['ERSEndPoint']['id']) {
               $deleteEndpoint = $ise->deleteEndpoint(
                  $isedev['name'],
                  $endpointDetails[$isedev['name']]['ERSEndPoint']['id']
               );

               if (preg_match('/success/i', $deleteEndpoint)) {
                  // Keep the list of deleted enapoints for logging
                  $endpoints[$endpoint] = $endpoint;

                  $success[] = "Endpoint <b>$endpoint </b>has been removed from ISE " . $isedev['name'];

                  $delete = "DELETE FROM lan_switch_nac WHERE endpoint LIKE '$endpoint'";

                  $del = $db->execute($delete);

                  if ($del) {
                     $success[] = "Endpoint <b>$endpoint</b> has been deleted from database";
                  } else {
                     $errors[] = "Could not delete endpoint <b>$endpoint</b> from database";
                  }
               } else {
                  $errors[] = $deleteEndpoint['ERSResponse']['message'];
               }
            }
         }
      }
   }

   if ($success) {
      print $html->success_message($success, "SUCCESS");
   }
   if ($errors) {
      print $html->error_message($errors, "ERROR");
   }

   if ($endpoints) {
      $logData = array(
         'action' => 'deleteAuthorizationBulk',
         'object' => array_keys($endpoints),
         'data' => array(),
         'status' => array('success' => $success, 'errors' => $errors),
      );

      $func->logchange($db, json_encode($logData));
   }

   $func->loadfinish();
}

function sectionRemediate()
{

   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $modal = $global['modal'];
   $func = $global['func'];
   $endpoint_str = $global["endpoint"];
   $allowedSwitches = $global['allowedSwitches'];
   $permission = $global['permission'];

   $searchSite = $global['searchSite'];

   $columns = array(
      'Endpoint' => 'endpoint',
      'Authorization' => 'authorization',
      'Authentication' => 'authentication',
      'Profile' => 'endpoint_profile',
      'IP Phone' => 'ipphone',
      'Switch' => 'switch_name',
      'Switch IP' => 'switch_ip',
      'Interface' => 'switch_port',
      'Description' => 'description',
      'Updated' => 'updated',
      'Updated By' => 'updated_by',
      'Last Seen' => 'last_seen',
   );

   $topts = array('datatable' => $endpoint_str ? 0 : 1, 'table.id' => 'remediate');

   $query = "SELECT * from lan_switch_nac where switch_name LIKE '%$searchSite%' AND " .
      "(authorization LIKE '%permanent%' OR authorization LIKE '%temp%' OR authorization LIKE '%remediate%') " .
      "AND authentication like 'failed' AND last_Seen > updated";

   $results = $db->query($query);

   // Added extra validation to not show results from LifeCycle section
   $endpoints = [];

   foreach ($results as $key => $props) {
      $endpoints[] = $props['endpoint'];
   }

   $logged_endpoints_query = "SELECT tool_log_id,JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) as object FROM tool_changelog WHERE tool_log_tool = 'lan.manager' " .
      "AND tool_log_desc LIKE '%authorizeEndpoint - Permanent%' AND JSON_UNQUOTE(json_extract(tool_log_desc,'$.status.errors')) LIKE '%null%' " .
      "AND JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) IN ('" . implode("','", $endpoints) . "')";

   $logged_endpoints_result = $db->query($logged_endpoints_query);

   $searchValue = '00:17:61:C2:6F:D9';

   $objectValues = array_column($logged_endpoints_result, 'object');
   unset($logged_endpoints_result);

   foreach ($results as $key => $props) {
      if (in_array($props['endpoint'], $objectValues)) {
         unset($results[$key]);
      }
   }

   unset($objectValues);

   //if endpoint was authorized and authentication is still failed and last seen > updated then show them here 
   foreach ($results as $id => $result) {
      $endpoint = $result['endpoint'];

      $results[$id]['endpoint'] = "<a href='/lan-manager.php?tab=Site&siteSection=Remediate&" .
         "searchSite=$searchSite&modal=History&endpoint=" . $endpoint . "' " .
         "data-toggle='tooltip' title='Click to see history'>$endpoint</a>";
   }

   if (preg_match('/History/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
      showEndpointHistoryModal();
   }

   $endpoints = getFilteredEndpoints($results, $permission, $allowedSwitches);

   print $html->table($endpoints, $columns, $topts);

   if ($endpoint_str) {
      echo "<script>"; {

         echo "
         const table = $('#remediate').DataTable();
   
         console.log(table);
   
         table.search('{$endpoint_str}').draw();
        ";

      }
      echo "</script>";
   }
}

function showAllSites()
{

   global $global;

   $tab = $global['tab'];
   $db = $global['db'];
   $html = $global['html'];
   $modal = $global['modal'];
   $locations = $global['locations'];
   $permission = $global['permission'];
   $restrictedLocations = $global['restrictedLocations'];
   $func = $global['func'];
   $username_str = $global["username_str"];
   $tool_name_str = $global["tool_name_str"];

   $columns = array(
      "Favorite" => "favorite",
      'Region' => 'region',
      'Location Code' => 'site',
      'Location' => 'location',
      'Mode' => 'mode',
      'Request Transition to Mode' => 'request',
      'Switches in DNAC' => 'primesw',
      'Switches in NAC' => 'nacsw',
      'Total Security Violations' => 'security_violations'
   );

   $favorite_location_arr = [];

   $db_req_str = "select * from user where user_name = '{$username_str}'";
   $db_res_arr = $db->query($db_req_str);

   foreach ($db_res_arr as $value_arr) {
      $preferences_arr = json_decode($value_arr["preferences"], true);
      $favorite_location_arr = $preferences_arr[$tool_name_str]["favorites"]["location"];
   }

   $topts = array('datatable' => 1, 'table.id' => 'allsites');

   $query = 'SELECT  site, region, count(*) as total, SUM(CASE WHEN mode like  "Monitor%" THEN 1 ELSE 0 END) AS monitor, ' .
      'SUM(CASE WHEN mode like  "Low%" THEN 1 ELSE 0 END) AS lowimpact,' .
      'SUM(CASE WHEN mode like  "Transition%" THEN 1 ELSE 0 END) AS transition,' .
      'SUM(CASE WHEN nac=1 THEN 1 ELSE 0 END) AS nacsw ' .
      'FROM lan_switch ' .
      'WHERE service LIKE "Managed" AND deleted = 0 GROUP BY site';

   $results = $db->query($query);

   foreach ($results as $site => $result) {
      if (preg_match('/limited|env/i', $permission) && !preg_grep('/' . substr($site, 2) . '/i', $restrictedLocations)) {
         continue;
      }

      $favorite_html_str = "<div style='display: flex; justify-content: center; align-items:center;'>"; {
         $make_device_favorite_base_hyperlink_str = $global["make_device_favorite_base_hyperlink_str"];

         $hyperlink_props_arr = [
            "level" => "level=location",
            "action" => "",
            "site_code" => "site_code={$result['site']}",
            "location" => "searchSite={$result['site']}",
         ];

         $icon_str = "";

         if (in_array(strtolower($result['site']), $favorite_location_arr)) {
            $hyperlink_props_arr["action"] = "action=remove";
            $icon_str = "yellow star icon";
         } else {
            $hyperlink_props_arr["action"] = "action=add";
            $icon_str = "star icon";
         }

         $make_device_favorite_hyperlink_str = $make_device_favorite_base_hyperlink_str . "&" . implode("&", $hyperlink_props_arr);
         $favorite_html_str .= "<a style='color: inherit; text-decoration: none;' href='{$make_device_favorite_hyperlink_str}'><i class='{$icon_str}'></i></a>";
      }
      $favorite_html_str .= "</div>";

      $item["favorite"] = $favorite_html_str;

      $item['region'] = $result['region'];
      $item['site'] = "<a href='/lan-manager.php?tab=Site&searchSite=" . $result['site'] . "'>" . $result['site'] . "</a>";
      $item['location'] = ($locations[$site]['location'] && !preg_match('/null/i', $locations[$site]['location'])) ?
         $locations[$site]['location'] : 'Unknown';

      // $sql_req       = "SELECT name FROM lan_switch WHERE name LIKE '%$location_code%' AND service LIKE '%Managed%' AND enabled = 1";
      // $sql_res       = $db->query($sql_req);

      $item['security_violations'] = count_security_violations($result['site']);

      $query_opts = [
         'output_mode' => 'json',
         'earliest_time' => '-10m',
         'count' => 0
      ];

      $item['mode'] = '';

      // Determine the site mode based on the switches mode from that site.
      if ($result['total'] == $result['monitor']) {
         $item['mode'] = 'Monitor Mode';
      } else if ($result['total'] == $result['lowimpact']) {
         $item['mode'] = 'Low Impact Mode';
      } else if ($result['transition']) {
         $item['mode'] = 'Transition to Low Impact Mode';
      } else if ($result['total'] == $result['monitor'] + $result['lowimpact']) {
         $item['mode'] = 'Hybrid';
      }
      $item['primesw'] = $result['total'];

      $searchSite = $result['site'];
      $item['request'] = (preg_match('/monitor|hybrid/i', $item['mode']) && preg_match('/basic|full/i', $permission)) ?
         "<a href= '/lan-manager.php?tab=AllSites&" .
         "searchSite=$searchSite&modal=RequestLowImpact'>Low Impact Mode</a>" : "";
      $item['nacsw'] = $result['nacsw'];

      // Highlight the table cells based on their values 

      if ($item['nacsw'] != $item['primesw']) {
         $topts['td']['style']['cell']['Switches in DNAC'][] = 'background:#ff9999;';
         $topts['td']['style']['cell']['Switches in NAC'][] = 'background:#ff9999;';
      } else {
         $topts['td']['style']['cell']['Switches in DNAC'][] = ''; //'background:#daf1e6';
         $topts['td']['style']['cell']['Switches in NAC'][] = ''; //'background:#daf1e6';
      }

      if (!$item['mode']) {
         $topts['td']['style']['cell']['Mode'][] = '';
         $topts['tr']['style'][] = '';

      } else if (preg_match('/monitor|hybrid/i', $item['mode'])) {
         $topts['td']['style']['cell']['Mode'][] = ''; // 'background:#ddeeee';
         $topts['tr']['style'][] = ''; //'background:#ddeeee';
      } else if (preg_match('/low impact/i', $item['mode'])) {
         if (preg_match('/transition/i', $item['mode'])) {
            $topts['tr']['style'][] = 'background:#ffe6cc';
            $topts['td']['style']['cell']['Mode'][] = 'background:#ffe6cc';
         } else {
            $topts['tr']['style'][] = 'background:#daf1e6';
            $topts['td']['style']['cell']['Mode'][] = 'background:#daf1e6';
         }
      } else if (preg_match('/unknown/i', $item['mode'])) {
         $topts['td']['style']['cell']['Mode'][] = 'background:#ff9999;';
         $topts['tr']['style'][] = '';
      }

      $items[] = $item;

   }

   if (preg_match('/RequestLowImpact/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
      showRequestLowImpactModal();
   }

   print $html->table($items, $columns, $topts);
}

function showRequestLowImpactModal()
{

   global $global;

   $html = $global['html'];
   $tab = $global['tab'];
   $db = $global['db'];

   $selectLocation = $global['selectLocation'];
   $searchSite = $global['searchSite'];

   $location = $selectLocation[$searchSite];

   $query = "SELECT name as Hostname,ipaddress as IP,mode as CurrentMode FROM lan_switch " .
      "WHERE site LIKE '$searchSite' AND mode LIKE 'Monitor%' AND enabled = 1 and nac = 1";
   $switches = $db->query($query);

   $query = "SELECT * FROM lan_switch_nac WHERE switch_name LIKE '$searchSite%' AND " .
      "authentication LIKE 'failed' " .
      "AND (authorization not like '%permanent%' OR authorization not like '%temp%') " .
      "ORDER BY last_seen DESC";

   $categorize = $db->query($query);

   $query = "SELECT * from lan_switch_nac where switch_name LIKE '%$searchSite%' AND " .
      "(authorization LIKE '%permanent%' OR authorization LIKE '%temp%') " .
      "AND authentication like 'failed' AND last_Seen > updated";

   $remediate = $db->query($query);

   print $html->startmodal("Request transition to Low Impact for $location", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   print "<div class='sixteen wide column'>";
   print "<br>";

   if ($switches) {
      print $html->info_message("Clicking Submit does <b>NOT</b> change site's mode right away. 
           This signifies the location wants or is ready to go to Low Impact mode", "NOTE");

      if (count($categorize)) {
         $categorizeLink = "<a href='/lan-manager.php?tab=Site&siteSection=Categorize&" .
            "searchSite=$searchSite'>here</a>";
         $warning[] = "There are <b>" . count($categorize) . "</b> devices that need categorization/authorization. " .
            "Ensure you authorize the required devices $categorizeLink.";
      }
      if (count($remediate)) {
         $remediateLink = "<a href='/lan-manager.php?tab=Site&siteSection=Remediate&" .
            "searchSite=$searchSite'>here</a>";
         $warning[] = "There are <b>" . count($remediate) . "</b> devices that need remediation. " .
            "Ensure you remediate the required devices $remediateLink.";
      }

      if ($warning) {
         print $html->warning_message($warning, 'WARNING');
      }

      print $html->table($switches);
   } else {
      print $html->warning_message("Could not find any eligible switch. Switch needs to be in Monitor Mode, 
       reachable and in NAC", "WARNING");
   }

   print "</div>";

   print $html->endmodalcontent();

   print "<br><br><br>";

   print $html->hidden_text('tab', 'AllSites');
   print $html->hidden_text('searchSite', $searchSite);

   if ($switches) {
      print $html->submit('submit', 'Submit Request', array('size' => 'tiny', 'color' => 'blue', 'align' => 'right floated'));
   }
   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();

}

function requestLowImpact()
{

   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];

   $searchSite = $global['searchSite'];

   $query = "SELECT name as Hostname,ipaddress as IP,mode as CurrentMode FROM lan_switch " .
      "WHERE site LIKE '$searchSite' AND mode LIKE 'Monitor%' AND enabled = 1";
   $switches = $db->query($query);

   composeMail($searchSite, $switches);

   // Update Mode to Transition to Low Impact
   foreach ($switches as $switch) {

      $update = "UPDATE lan_switch SET mode ='Transition to Low Impact' where name like '" . $switch['Hostname'] . "'";
      $upd = $db->execute($update);

      if ($upd) {
         $success[] = "Switch " . $switch['Hostname'] . " mode changed to Transition to Low Impact";
      } else {
         $errors[] = "Could not update switch " . $switch['Hostname'] . " mode to Transition to Low Impact";
      }
   }

   if (!$errors) {
      print $html->success_message("Request was submitted. Someone from the Managed LAN Service team will be in touch with you.", "SUCCESS");
   } else {
      print $html->error_message($errors, "FAILED");
   }

   $logData = array(
      'action' => 'requestTransitiontoLowImpact',
      'object' => implode('; ', array_column($switches, 'Hostname')),
      'data' => '',
      'status' => array('success' => $success, 'errors' => $errors),
   );

   $func->logchange($db, json_encode($logData));
}

function composeMail($searchSite, $switches)
{
   global $global;

   $mail = $global['mail'];
   $userinfo = $global['userinfo'];

   $subject = "";

   $to = 'DLECNSManagedLANServiceEngineering@emerson.com';

   $from = 'epic.nac@emerson.com';

   $subject = "Request trantision to low impact for $searchSite";

   $body = "Hi" .
      "<br><br>" .
      "User " . $userinfo['username'] . " reqested transiton to low impact for site <b>$searchSite</b> switches" .
      "<br><br>" . implode('<br>', array_keys($switches));

   $sendEmail = $mail->sendhtml($to, $from, $subject, $body, $headers);
}

function showAdmin()
{

   global $global;

   $html = $global['html'];
   $input = $global['input'];
   $db = $global['db'];
   $tab = $global['tab'];
   $submit = $global["submit"];
   $permission = $global['permission'];

   $adminSection = $global['adminSection'];
   $selectLocation = $global['selectLocation'];

   $searchSite = $global['searchSite'];

   $color['ChangeSiteMode'] = (preg_match('/change/i', $adminSection)) ? 'teal' : 'gray';
   $color['SyncWithCatalystCenter'] = (preg_match('/sync/i', $adminSection)) ? 'teal' : 'gray';
   $color['Manage Banners'] = (preg_match('/manage/i', $adminSection)) ? 'teal' : 'gray';
   $color['UpdateADGroup'] = (preg_match('/ad group/i', $adminSection)) ? 'teal' : 'gray';
   $color['UpdateUserExtraLocationAccess'] = (preg_match('/extra location access/i', $adminSection)) ? 'teal' : 'gray';

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->submit('adminSection', 'Sync With Catalyst Center', array('color' => $color['SyncWithCatalystCenter']));

   if (preg_match('/full/i', $permission)) {
      print $html->submit('adminSection', 'Change Site Mode', array('color' => $color['ChangeSiteMode']));
      print $html->submit('adminSection', 'Update AD Group', array('color' => $color['UpdateADGroup']));
      print $html->submit('adminSection', 'Update User Extra Location Access', ['color' => $color['UpdateUserExtraLocationAccess']]);
   }

   if (preg_match('/fulladmin/i', $permission)) {
      print $html->submit('adminSection', 'Manage Banners', array('color' => $color['Manage Banners']));
   }

   print $html->hidden_text('tab', 'Admin');
   print $html->hidden_text('searchSite', $searchSite);
   print $html->endform();

   print "<br><br>";

   $section = preg_replace('/ /', '', 'section' . $adminSection);

   //If statement was necessary to not show double tables when transitioning site to low impact
   if ($submit != "Submit Change") {
      call_user_func($section);
   }
}

function sectionUpdateUserExtraLocationAccess()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $htmxUrl = $global["htmx_page_url_str"];

   $formProps = [
      'id' => 'renderAdUserExtraLocationAccessTableForm',
      'method' => 'post',
      'hx-post' => $htmxUrl,
      'hx-target' => '#adUserExtraLocationAccessTableDiv',
      'hx-swap' => 'innerHTML',
   ];

   $formData = [
      'func_name' => 'renderAdUserExtraLocationAccessTable',
   ];

   $adObject = '';

   echo $html->info_message("By default, users only have access to locations linked to their site code. Use this feature only to grant extra location access for users who already belong to the appropriate AD groups", "Info");

   echo "<div id='sectionDiv' style='margin-top: 10px;'>"; {
      echo $html->startform($formProps); {
         echo "<div style='display: flex; flex-direction: row; gap: 10px;'>"; {
            echo $html->input_text('adObject', $adObject, 60, 60, array('placeholder' => 'Search AD User'));

            echo $html->submit(
               'searchAdUserButton',
               'Search AD User',
               [
                  'color' => 'gray',
                  'disable' => true
               ]
            );
         }
         echo '</div>';

         echo $html->form_data($formData);
      }
      echo $html->endform();

      echo $html->loading_message(
         'loadMessageSearchAdUserExtraLocationAccess',
         'Just a second',
         'Please, wait while we are preparing the data for you.',
         [
            'style' => 'margin-top: 10px; margin-bottom: 10px; display: none;'
         ]
      );

      echo "<div id='adUserExtraLocationAccessTableDiv' style='margin-top: 10px; display: none;'></div>";
   }
   echo "</div>";
}

function renderAdUserExtraLocationAccessTable()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $htmxUrl = $global["htmx_page_url_str"];
   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];

   $subFuncName = $input->get('sub_func_name', 'all');

   if ($subFuncName) {
      call_user_func($subFuncName);
   }

   $adObject = $input->get('adObject', 'all');

   $filter = "(&(objectClass=user)(|(displayname=$adObject)))";
   $attributes = ['sAMAccountName'];
   $adSearchResult = $ldap->search($adbasedn, $filter, $attributes);

   $adUser = $adSearchResult[0]['samaccountname'][0];

   if (!$adUser) {
      $adUser = $input->get('adUser', 'all');
   }

   if (!$adUser) {
      echo $html->error_message('Could not determine AD User.', 'Error');
   }

   $query = "select * from lan_management_rbac_location where user_name = '$adUser' and deleted = 0";
   $dbLanManagementRbacLocations = $db->query($query);

   if (!$dbLanManagementRbacLocations) {
      echo $html->info_message("No extra locations have been added for this user, $adUser.", "Info");
   }

   echo "<div style='clear: both;'></div>";
   echo "<div id='extraLocationAccessTableQuickOperationsMenu' style='display: flex; flex-direction: row; gap: 10px;'>"; {
      $modalId = 'addLocationsToAdGroupModal';
      $buttonId = 'addLocationsToAdGroupButton';

      $formData = [
         'adUser' => $adUser,
         'func_name' => "renderAddLocationsToAdGroupModalContent",
      ];

      $formProps = [
         'id' => "{$modalId}Form",
         'method' => 'post',
         'hx-post' => $htmxUrl,
         'hx-target' => "#{$modalId} .content",
         'hx-trigger' => 'click',
         'hx-swap' => 'innerHTML',
      ];

      echo $html->startform($formProps); {
         echo $html->submit(
            $buttonId,
            'Add Location(s)',
            [
               'color' => 'green',
            ]
         );
         echo $html->form_data($formData);
      }
      echo $html->endform();

      echo $html->standard_modal(
         $modalId,
         'Add Location(s)',
         '',
         [
            'style' => 'display: none;',
            'closeable' => true
         ]
      );

      $modalId = 'removeLocationsFromAdGroupModal';
      $buttonId = 'removeLocationsFromAdGroupButton';

      $formData['func_name'] = "renderRemoveLocationsFromAdGroupModalContent";

      $formProps['id'] = "{$modalId}Form";
      $formProps['hx-target'] = "#{$modalId} .content";

      echo $html->startform($formProps); {
         echo $html->submit(
            $buttonId,
            'Remove Location(s)',
            [
               'color' => 'red',
            ]
         );
         echo $html->form_data($formData);
      }
      echo $html->endform();

      echo $html->standard_modal(
         'removeLocationsFromAdGroupModal',
         'Remove Location(s)',
         '',
         [
            'style' => 'display: none;',
            'closeable' => true
         ]
      );
   }
   echo "</div>";
   echo "<div style='clear: both;'></div>";

   $htmlTable = [
      'columns' => [
         'AD Group' => 'adGroup',
         'Location Code(s)' => 'locationCodes',
         'Updated By' => 'updatedBy',
         'Updated At' => 'updatedAt'
      ],
      'rows' => [],
      'config' => [
         'table.id' => 'locationCodesAdGroups',
         'datatable' => true,
         'datatable.options' => [
            'pageLength' => -1
         ],
         'table.style' => 'font-size: 0.8em'
      ]
   ];

   foreach ($dbLanManagementRbacLocations as $value) {
      $adGroup = $value['ad_group'];
      $updatedBy = $value['updated_by'];
      $updatedAt = $value['updated_at'];
      $locationCodes = json_decode($value['location_codes'], true);

      $htmlTable['rows'][] = [
         'adGroup' => $adGroup,
         'locationCodes' => implode(', ', $locationCodes),
         'updatedBy' => $updatedBy,
         'updatedAt' => $updatedAt,
      ];
   }


   echo "<div style='clear: both;'></div>";

   echo "<div style='margin-top: 10px;'>"; {
      echo $html->table($htmlTable['rows'], $htmlTable['columns'], $htmlTable['config']);
   }
   echo "</div>";
}

/**
 * 
 * @param LDAP $ldap
 * @param string $adbasedn
 * @param string $adUser
 * @param array $adGroups
 * @return array
 */
function getUserAdGroupsOptions($ldap, $adbasedn, $adUser, $adGroups)
{
   foreach ($adGroups as $key => $adGroup) {
      if ($adGroup === 'Managed LAN Service Local IT') {
         unset($adGroups[$key]);
         break;
      }
   }

   $adUserGroups = $ldap->get_user_groups($adbasedn, $adUser);
   $adGroupOptions = [];

   foreach ($adUserGroups as $adUserGroup => $value) {
      if (in_array($adUserGroup, $adGroups)) {
         $adGroupOptions[$adUserGroup] = $adUserGroup;
      }
   }

   return $adGroupOptions;
}

function renderAddLocationsToAdGroupModalContent()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $htmxUrl = $global["htmx_page_url_str"];
   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];
   $snow = $global['snow'];
   $adGroups = $global['adGroups'];

   $adUser = $input->get('adUser', 'all');
   $adUserLocationCode = getAdUserLocationCode($ldap, $adbasedn, $adUser) ?? null;

   $snowLocations = getAllSnowLocations($db, $snow);

   foreach ($snowLocations as $index => $location) {
      if (preg_match('/' . $adUserLocationCode . '/i', $location) && $adUserLocationCode) {
         unset($snowLocations[$index]);
      }
   }

   $formProps = [
      'id' => 'renderAdUserExtraLocationAccessTableForm',
      'method' => 'post',
      'hx-post' => $htmxUrl,
      'hx-target' => '#adUserExtraLocationAccessTableDiv',
      'hx-swap' => 'innerHTML',
   ];

   $formData = [
      'func_name' => 'renderAdUserExtraLocationAccessTable',
      'sub_func_name' => 'addLocationsToAdGroup',
      'adUser' => $adUser,
   ];

   $adGroupOptions = getUserAdGroupsOptions($ldap, $adbasedn, $adUser, $adGroups);

   echo $html->startform($formProps); {
      echo "<div style='display: flex; flex-direction: column; gap: 10px;'>"; {

         echo "<div>"; {
            echo $html->semantic_ui_dropdown(
               "selectAdGroup",
               "Select AD Group",
               $adGroupOptions,
               ["do_initialize" => true]
            );
         }
         echo "</div>";

         echo "<div>"; {
            echo $html->semantic_ui_multiple_select(
               "selectLocations",
               "Select Location(s) to add",
               $snowLocations,
               [
                  "keys_as_values" => true,
                  "dropdown_class" => "ui fluid search dropdown",
                  "search" => true,
                  "do_initialize" => false,
               ]
            );
         }
         echo "</div>";

         echo "<div style='align-self: flex-end;'>"; {
            echo $html->submit(
               "submitAddLocationsButton",
               "Add Location(s)",
               [
                  "color" => "green",
                  "disable" => false,
               ]
            );
         }
         echo "</div>";
      }
      echo '</div>';

      echo $html->form_data($formData);
   }
   echo $html->endform();
}

function addLocationsToAdGroup()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $userinfo = $global['userinfo'];
   $username = $userinfo['username'];

   $adUser = $input->get('adUser', 'all') ? $input->get('adUser', 'all') : null;
   $adGroup = $input->get('selectAdGroup', 'all') ? $input->get('selectAdGroup', 'all') : null;
   $locationCodes = $input->get('selectLocations', 'all');

   if (!$adUser) {
      echo $html->error_message('Could not detect AD user.', 'Error');
      return;
   }

   if (!$adGroup) {
      echo $html->error_message('Could not detect AD group.', 'Error');
      return;
   }

   $query = "select user_name, ad_group, location_codes from lan_management_rbac_location where user_name = '$adUser'";
   $dbLanManagementRbacLocations = $db->query($query);

   $dbLocationCodes = json_decode($dbLanManagementRbacLocations[$adUser]['location_codes'], true);

   foreach ($locationCodes as $locationCode) {
      if (!in_array($locationCode, $dbLocationCodes)) {
         $dbLocationCodes[] = $locationCode;
      }
   }

   $locationCodesJson = json_encode($dbLocationCodes);
   $locationCodesJson = $db->escape_string($locationCodesJson);

   $upsertQuery = "insert into lan_management_rbac_location(user_name, ad_group, location_codes, updated_by, updated_at) values ('$adUser', '$adGroup', '$locationCodesJson', '$username', CURRENT_TIMESTAMP) on duplicate key update location_codes = VALUES(location_codes), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP";
   $upsertResult = $db->execute($upsertQuery);

   $success = [];
   $error = [];

   if ($upsertResult) {
      $success = $locationCodes;
   } else {
      $error = $locationCodes;
   }

   if ($success) {
      echo $html->success_message($success, "Successfully added the following locations to AD Group $adGroup for $adUser");
   }

   if ($error) {
      echo $html->error_message($error, "Failed to add the following locations to AD Group $adGroup for $adUser");
   }

   $logData = [
      'action' => 'addLocationsToAdGroup',
      'object' => $adGroup,
      'data' => ['locationCodes' => $locationCodes, 'adUser' => $adUser, 'adGroup' => $adGroup],
      'status' => ['success' => $success, 'error' => $error]
   ];

   $func->logchange($db, json_encode($logData));
}

/**
 * Summary of getAdUserLocationCode
 * @param LDAP $ldap
 * @param string $adbasedn
 * @param string $adUser
 * @return string
 */
function getAdUserLocationCode($ldap, $adbasedn, $adUser)
{
   $adUserLocationCode = '';

   $normalUser = $ldap->get_exchextattr_from_samaccountname($adbasedn, $adUser, 26);

   if (!$normalUser) {
      $adUserLocationCode = $ldap->get_exchextattr_from_samaccountname($adbasedn, $adUser, 30);
   } else {
      $adUserLocationCode = $ldap->get_exchextattr_from_samaccountname($adbasedn, $normalUser, 30);
   }

   return $adUserLocationCode;
}

/**
 * Summary of getAllSnowLocations
 * @param MySQL $db
 * @param ServiceNow $snow
 * @return array
 */
function getAllSnowLocations($db, $snow)
{
   $query = "select site from lan_switch WHERE service LIKE 'Managed' AND JSON_VALID(details) and  JSON_EXTRACT(details, \"$.sysinfo.location\") is not null group by site";
   $dbSwitches = $db->query($query);
   $dbLocationCodes = [];

   foreach ($dbSwitches as $dbSwitch) {
      preg_match('/l\d{5}/i', $dbSwitch['site'], $match);
      $locationCode = $match[0];

      if (!$locationCode) {
         continue;
      }

      $dbLocationCodes[$locationCode] = $locationCode;
   }

   $snowLocations = [];

   $params = array(
      'sysparm_fields' => 'name',
      'sysparm_display_value' => 'all'
   );

   $snowResponse = $snow->getLocation($params);

   foreach ($snowResponse['result'] as $value) {
      $location = $value['name']['display_value'];

      if (!$location) {
         continue;
      }

      preg_match('/l\d{5}/i', $location, $match);
      $locationCode = $match[0];

      if (!$locationCode) {
         continue;
      }

      if (!in_array($locationCode, $dbLocationCodes)) {
         continue;
      }

      $snowLocations[$locationCode] = $location;
   }

   asort($snowLocations);

   return $snowLocations;
}

function renderRemoveLocationsFromAdGroupModalContent()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $htmxUrl = $global["htmx_page_url_str"];
   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];
   $snow = $global['snow'];
   $adGroups = $global['adGroups'];

   $adUser = $input->get('adUser', 'all');

   $formProps = [
      'id' => 'renderExistingLocationsFromAdGroupForm',
      'method' => 'post',
      'hx-post' => $htmxUrl,
      'hx-target' => '#locationsFromAdGroupDiv',
      'hx-swap' => 'innerHTML',
      'hx-trigger' => 'change',
   ];

   $formData = [
      'func_name' => 'renderExistingLocationsFromAdGroup',
      'adUser' => $adUser,
   ];

   $adGroupOptions = getUserAdGroupsOptions($ldap, $adbasedn, $adUser, $adGroups);

   echo $html->startform($formProps); {
      echo "<div style='display: flex; flex-direction: column; gap: 10px;'>"; {

         echo "<div>"; {
            echo $html->semantic_ui_dropdown(
               "selectAdGroup",
               "Select AD Group",
               $adGroupOptions,
               ["do_initialize" => true]
            );
         }
         echo "</div>";
      }
      echo '</div>';

      echo $html->form_data($formData);
   }
   echo $html->endform();

   echo "<div id='locationsFromAdGroupDiv' style='margin-top: 10px;'></div>";
}

function renderExistingLocationsFromAdGroup()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $htmxUrl = $global["htmx_page_url_str"];
   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];
   $snow = $global['snow'];
   $adGroups = $global['adGroups'];

   $adGroup = $input->get('selectAdGroup', 'all');
   $adUser = $input->get('adUser', 'all');
   $adUserLocationCode = getAdUserLocationCode($ldap, $adbasedn, $adUser) ?? null;

   $query = "select ad_group, location_codes from lan_management_rbac_location where ad_group = '$adGroup'";
   $dbLanManagementRbacLocations = $db->query($query);

   $adGroupLocationCodes = json_decode($dbLanManagementRbacLocations[$adGroup]['location_codes'], true);

   $snowLocations = getAllSnowLocations($db, $snow);

   foreach ($snowLocations as $index => $location) {
      if (preg_match('/' . $adUserLocationCode . '/i', $location) && $adUserLocationCode) {
         unset($snowLocations[$index]);
      }

      if (!in_array($index, $adGroupLocationCodes)) {
         unset($snowLocations[$index]);
      }
   }

   $formProps = [
      'id' => 'renderAdUserExtraLocationAccessTableForm',
      'method' => 'post',
      'hx-post' => $htmxUrl,
      'hx-target' => '#adUserExtraLocationAccessTableDiv',
      'hx-swap' => 'innerHTML',
   ];

   $formData = [
      'func_name' => 'renderAdUserExtraLocationAccessTable',
      'sub_func_name' => 'removeLocationsFromAdGroup',
      'adUser' => $adUser,
      'adGroup' => $adGroup,
   ];

   echo $html->startform($formProps); {
      echo "<div style='display: flex; flex-direction: column; gap: 10px;'>"; {
         echo "<div>"; {
            echo $html->semantic_ui_multiple_select(
               "selectLocations",
               "Select Location(s) to remove",
               $snowLocations,
               [
                  "keys_as_values" => true,
                  "dropdown_class" => "ui fluid search dropdown",
                  "search" => true,
                  "do_initialize" => true,
               ]
            );
         }
         echo "</div>";

         echo "<div style='align-self: flex-end;'>"; {
            echo $html->submit(
               "submitRemoveLocationsButton",
               "Remove Location(s)",
               [
                  "color" => "red",
                  "disable" => false,
               ]
            );
         }
         echo "</div>";
      }
      echo '</div>';

      echo $html->form_data($formData);
   }
   echo $html->endform();
}

function removeLocationsFromAdGroup()
{
   global $global;

   $db = $global['db'];
   $html = $global['html'];
   $func = $global['func'];
   $input = $global['input'];
   $userinfo = $global['userinfo'];
   $username = $userinfo['username'];

   $adUser = $input->get('adUser', 'all') ? $input->get('adUser', 'all') : null;
   $adGroup = $input->get('adGroup', 'all') ? $input->get('adGroup', 'all') : null;
   $locationCodes = $input->get('selectLocations', 'all');

   if (!$adUser) {
      echo $html->error_message('Could not detect AD user.', 'Error');
      return;
   }

   if (!$adGroup) {
      echo $html->error_message('Could not detect AD group.', 'Error');
      return;
   }

   $query = "select user_name, ad_group, location_codes from lan_management_rbac_location where user_name = '$adUser' and ad_group = '$adGroup'";
   $dbLanManagementRbacLocations = $db->query($query);

   $dbLocationCodes = json_decode($dbLanManagementRbacLocations[$adUser]['location_codes'], true);

   foreach ($locationCodes as $locationCode) {
      foreach ($dbLocationCodes as $index => $dbLocationCode) {
         if ($locationCode == $dbLocationCode) {
            unset($dbLocationCodes[$index]);
         }
      }
   }

   $dbLocationCodes = array_values($dbLocationCodes);

   $locationCodesJson = json_encode($dbLocationCodes);
   $locationCodesJson = $db->escape_string($locationCodesJson);

   $updateQuery = "update lan_management_rbac_location set location_codes = '$locationCodesJson', updated_by = '$username' where user_name = '$adUser' and ad_group = '$adGroup'";
   $updateResult = $db->execute($updateQuery);

   $success = [];
   $error = [];

   if ($updateResult) {
      $success = $locationCodes;
   } else {
      $error = $locationCodes;
   }

   if ($success) {
      echo $html->success_message($success, "Successfully removed the following locations from AD Group $adGroup for $adUser");
   }

   if ($error) {
      echo $html->error_message($error, "Failed to remove the following locations from AD Group $adGroup for $adUser");
   }

   $logData = [
      'action' => 'removeLocationsFromAdGroup',
      'object' => $adGroup,
      'data' => ['locationCodes' => $locationCodes, 'adUser' => $adUser, 'adGroup' => $adGroup],
      'status' => ['success' => $success, 'error' => $error]
   ];

   $func->logchange($db, json_encode($logData));
}

function sectionChangeSiteMode()
{

   global $global;

   $html = $global['html'];
   $db = $global['db'];
   $modal = $global['modal'];

   $permission = $global['permission'];

   $selectLocation = $global['selectLocation'];

   if (!preg_match('/full/i', $permission)) {
      return;
   }

   $columns = array(
      'Region' => 'region',
      'Site' => 'site',
      'Location' => 'location',
      'Number of Switches' => 'count',
      'Action' => 'action',
   );

   $query = 'SELECT  site, region, count(*) AS count ' .
      'FROM lan_switch ' .
      'WHERE service LIKE "Managed" AND mode LIKE "Transition%" AND enabled = 1 AND nac = 1 GROUP BY site';

   $results = $db->query($query);

   foreach ($results as $id => $result) {
      $results[$id]['location'] = $selectLocation[$result['site']];
      $results[$id]['action'] = "<a href='/lan-manager.php?tab=Admin&adminSection=Change Site Mode&" .
         "modal=ChangeToLowImpact&searchSite=" . $result['site'] . "'>Change to Low Impact</a>";

   }

   if (preg_match('/ChangeToLowImpact/i', $modal) && !preg_match('/cancel|ok/i', $submit)) {
      showChangeToLowImpactModal();
   }

   print $html->table($results, $columns);
}

function showChangeToLowImpactModal()
{

   global $global;

   $html = $global['html'];
   $tab = $global['tab'];
   $db = $global['db'];

   $selectLocation = $global['selectLocation'];
   $searchSite = $global['searchSite'];

   $location = $selectLocation[$searchSite];

   $warning[] = "Changing a site to low impact mode will deny network access to clients that cannot " .
      "authenticate successfully! ";
   $warning[] = "Domain computers and devices that were in lifeCycle will be able to connect successfully.";
   $warning[] = "The following swithes/interfaces will be changed to low impact mode.";
   $warning[] = "<b>Please check that no uplink interface is present on the list!!!</b>";

   $query = "SELECT id, name,ipaddress,details FROM lan_switch " .
      "WHERE site LIKE '$searchSite' AND service LIKE 'Managed' AND mode LIKE 'Transition%' " .
      "AND enabled = 1 AND nac = 1";

   $switches = $db->query($query);

   print $html->startmodal("Request transition to Low Impact for $location", true);

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));

   print $html->startmodalcontent();

   print "<div class='sixteen wide column'>";
   print "<br>";

   if ($switches) {

      foreach ($switches as $switch) {

         $details = json_decode($switch['details'], true);

         foreach ($details['interfaces'] as $key => $interface) {
            // we are considering only the interfaces in access mode
            if ($interface['ifmode'] != 2) {
               continue;
            }

            $item['Exclude'] = $html->checkbox($switch['id'] . '_' . $key, $interface['ifname']);
            $item['Switch'] = $switch['name'];
            $item['Interface'] = $interface['ifname'];
            $item['Description'] = $interface['ifdesc'];
            $item['Status'] = ($interface['ifoperstatus'] == 1) ? 'Up' : 'Down';

            $items[] = $item;
         }
      }
      print $html->warning_message($warning, "WARNING");

      print $html->info_message("Select ports that you don't want NAC to be enabled on", "Exclude Info");

      echo "<div style='overflow-y: scroll; height: 300px;'>";
      print $html->table($items, array(), array('datatable' => 0, 'table.id' => 'ChangeLowImpact'));
      echo "</div>";
   } else {
      print $html->warning_message("Could not find any eligible switch. 
          Switches need to be reachable and in NAC.", "WARNING");
   }

   print "</div>";

   print $html->endmodalcontent();

   print "<br><br><br>";

   print $html->hidden_text('tab', 'Admin');
   print $html->hidden_text('adminSection', 'Change Site Mode');
   print $html->hidden_text('searchSite', $searchSite);

   if ($switches) {
      print $html->submit(
         'submit',
         'Submit Change',
         array(
            'size' => 'tiny',
            'color' => 'orange',
            'align' => 'right floated'
         )
      );
   }

   print $html->submit('submit', 'Cancel', array('size' => 'tiny', 'color' => 'basic', 'align' => 'right floated'));

   print "<br><br><br><br>";

   print $html->endform();

   print $html->endmodal();
}

function changeToLowImpact()
{
   global $global, $toolvalue;

   $db = $global['db'];
   $user_name = $global['userinfo']['username'];
   $html = $global['html'];
   $func = $global['func'];
   $site = $global['searchSite'];
   $tool_name = $global['tool_name_str'];
   $permission = $global['permission'];
   $input = $global['input'];

   $bounce_ports = !empty($input->get('bounce_ports_toggle', 'all')) ? $func->encrypt_var($tool_name, 1) : $func->encrypt_var($tool_name, 0);

   $sql_req = "SELECT ipaddress, name, id, details FROM lan_switch WHERE site = '$site' AND service = 'Managed' AND mode LIKE 'Transition%' AND enabled = 1 AND nac =1";
   $sql_res = $db->query($sql_req);

   foreach ($sql_res as $key => $props) {
      $sql_res[$key]['status'] = "<div id='transition_status_{$props['ipaddress']}'><div class='ui mini active inline loader' style='margin-right: 10px;'></div>Please wait...</div>";
      $devices[$props['name']] = $key;
      $details = json_decode($props['details'], true);

      foreach ($details['interfaces'] as $index => $interface) {
         $exclude_port = $input->get($props['id'] . "_" . $index, 'all');

         if (empty($exclude_port)) {
            continue;
         }

         $exclude_ports[$key][] = $interface['ifname'];
      }
   }

   $topts = [
      'datatable' => 0,
      'table.id' => 'low_impact_switches'
   ];

   $columns = [
      'Switch IP' => 'ipaddress',
      'Switch Name' => 'name',
      'Status' => 'status'
   ];

   if (count($sql_res) > 4) {
      $overflow_div = "<div style='margin-top:5px; margin-bottom: 10px; height: 500px; overflow-y:scroll'>";
   } else {
      $overflow_div = "<div style='margin-top:5px; margin-bottom: 10px;'>";
   }

   echo low_impact_loading_banner();
   echo $overflow_div;
   echo "<div>";
   // echo $html->info_message("Table below is used to show current progress on the tranistion to low impact of current site", "Loading Banner");
   echo $html->table($sql_res, $columns, $topts);
   echo "</div>";
   echo "</div>";

   echo "<div id='low_impact_special_error'>";
   echo "</div>";
   echo $html->warning_message("Do not click any of the buttons below until the transition process above has been completed.", "Warning:");
   ksort($devices);
   $devices = array_values($devices);

   foreach ($devices as $key => $value) {
      $devices[$key] = $func->encrypt_var($tool_name, $value, 1);
   }

   $exclude_ports = base64_encode(json_encode($exclude_ports));

   echo $html->hidden_text('low_impact_queue_list', json_encode($devices));
   echo $html->hidden_text('low_impact_user', $func->encrypt_var($tool_name, $user_name, 1));
   echo $html->hidden_text('low_impact_user_perm', $func->encrypt_var($tool_name, $permission, 1));
   echo $html->hidden_text('low_impact_tool_name', $tool_name);
   echo $html->hidden_text('low_impact_bounce_ports', $bounce_ports);
   echo $html->hidden_text('low_impact_exclude_ports', json_encode($exclude_ports));
}

function low_impact_loading_banner()
{
   $banner .= "<div class=\"ui tiny info message\">";
   $banner .= "<div class=\"header\">";
   $banner .= "Loading Banner";
   $banner .= "</div>";
   $banner .= "<p>";
   $banner .= "You cannot move to any other tabs while the transition process is still running, please wait for it to complete, EPIC will perform the following steps";
   $banner .= "</p>";
   $banner .= "<ol class=\"ui list\">";
   $banner .= "<li>Change mode of switch in ISE from monitor mode to Low Impact.</li>";
   $banner .= "<li>clear auth session executed on the switch.</li>";
   $banner .= "<li>Pause for 1 minute for the devices to re-authenticate.</li>";
   $banner .= "<li>For IBNSv2 switches only, the below ACL will be applied to all interfaces not selected on the previous screen</li>";
   $banner .= "<ul class=\"\">";
   $banner .= "ip access-group ACL-DEFAULT in.";
   $banner .= "</ul>";
   $banner .= "</ol>";
   $banner .= "</div>";

   return $banner;
}

function updateIseDeviceMode($switchName)
{

   global $global;

   $isedevs = $global['isedevs'];
   $ise = $global['ise'];

   // We don't use dns suffix when searching for the device in ISE 
   $search = explode('.', $switchName);

   foreach ($isedevs['radius'] as $isedev) {
      $filter['criteria'] = array(array('name', 'STARTSW', $search[0]));

      // Get the ids of the devices matching the criteria 
      $searchDevice = $ise->getNetworkDevices($isedev['name'], '', $filter);

      if ($searchDevice[0]['id']) {
         $deviceDetails = $ise->getNetworkDevices($isedev['name'], $searchDevice[0]['id']);

         foreach ($deviceDetails['NetworkDevice']['NetworkDeviceGroupList'] as $id => $devGroup) {
            if (preg_match('/Mode#All Modes/i', $devGroup)) {
               $deviceDetails['NetworkDevice']['NetworkDeviceGroupList'][$id] = "Mode#All Modes#Low Impact";
            }
         }

         $edit = $ise->updateNetworkDevice($isedev['name'], $deviceDetails, $searchDevice[0]['id']);

         if (preg_match('/success/', $edit)) {
            $return[] = "<i class='green checkmark icon'></i>Device $switchName has been " .
               "set as Low Impact Mode on ISE " . $isedev['name'];
         } else {
            $return[] = "<i class='red attention icon'></i>Could not set mode to " .
               "Low Impact for device  $switchName on ISE " . $isedev['name'];
         }
      }
   }

   foreach ($isedevs['radius.china'] as $isedev) {
      $filter['criteria'] = array(array('name', 'STARTSW', $search[0]));

      // Get the ids of the devices matching the criteria 
      $searchDevice = $ise->getNetworkDevices($isedev['name'], '', $filter);

      if ($searchDevice[0]['id']) {
         $deviceDetails = $ise->getNetworkDevices($isedev['name'], $searchDevice[0]['id']);

         foreach ($deviceDetails['NetworkDevice']['NetworkDeviceGroupList'] as $id => $devGroup) {
            if (preg_match('/Mode#All Modes/i', $devGroup)) {
               $deviceDetails['NetworkDevice']['NetworkDeviceGroupList'][$id] = "Mode#All Modes#Low Impact";
            }
         }

         $edit = $ise->updateNetworkDevice($isedev['name'], $deviceDetails, $searchDevice[0]['id']);

         if (preg_match('/success/', $edit)) {
            $return[] = "<i class='green checkmark icon'></i>Device $switchName has been " .
               "set as Low Impact Mode on ISE " . $isedev['name'];
         } else {
            $return[] = "<i class='red attention icon'></i>Could not set mode to " .
               "Low Impact for device  $switchName on ISE " . $isedev['name'];
         }
      }
   }

   return $return;
}

function sectionManageBanners()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];
   $db = $global['db'];
   $submit = $global['submit'];
   $adminSection = $global['adminSection'];

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->hidden_text('tab', 'Admin');
   print $html->hidden_text('adminSection', $adminSection);
   print $html->submit('submit', 'Create Banner', array('color' => 'blue'));
   print '<br><br>';
   print $html->endform();

   showTable();

   if (preg_match('/Create Banner/i', $submit))
      showCreateBannerModal();

   if (preg_match('/Delete Banner/i', $submit))
      deleteBannerModal();

   if (preg_match('/Edit Banner/i', $submit))
      editBannerModal();

   if (preg_match('/View Banner/i', $submit))
      viewBannerModal();

   if (preg_match('/History Banner/i', $submit))
      historyBannerModal();
}

function showTable()
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];

   $topts = array('datatable' => 1, 'table.id' => 'banners');

   $columns = array(
      'ID' => 'id',
      'Created By' => 'created_by',
      'Banner Code' => 'banner_code',
      'Banner Description' => 'banner_desc',
      'Action' => 'action'
   );

   $statement = "SELECT * from lan_switch_banner";
   $queryResult = $db->query($statement);

   $count = 1;
   foreach ($queryResult as $id => $value) {
      $banners[$count]['id'] = $count;
      $banners[$count]['created_by'] = $value['created_by'];
      $banners[$count]['created_at'] = $value['created_at'];
      $banners[$count]['edited_by'] = $value['edited_by'] ? $value['edited_by'] : 'Not edited';
      $banners[$count]['edited_at'] = ($value['created_at'] == $value['edited_at']) ? 'Not edited' : $value['edited_at'];
      $banners[$count]['banner_code'] = $value['banner_code'];
      $banners[$count]['banner_desc'] = $value['banner_desc'];
      $banners[$count]['banner_content'] = $value['banner_content'];
      $banners[$count]['action'] = "<a href='lan-manager.php?tab=Admin&adminSection=Manage Banners&submit=View Banner&bannerID=" . $id . "'>" . "<i class='eye layout icon'></i>" . "</a>" .
         "<a href='lan-manager.php?tab=Admin&adminSection=Manage Banners&submit=Edit Banner&bannerID=" . $id . "'>" . "<i class='edit layout icon'></i>" . "</a>" .
         "<a href='lan-manager.php?tab=Admin&adminSection=Manage Banners&submit=Delete Banner&bannerID=" . $id . "'>" . "<i class='remove icon'></i>" . "</a>";

      $count++;
   }

   print $html->table($banners, $columns, $topts);
}

function showCreateBannerModal()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];

   print $html->startmodal("Create Banner", true); //true property brings a close icon next to the div box, but you can close it without the 'x' icon. It is just not as intuitive for somee poeple
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->input_text('bannerCode', $bannerCode, 2048, 80, array('placeholder' => 'Enter Banner Code'));
   print "<br/><br/>";
   print $html->input_text('bannerDesc', $bannerDesc, 2048, 80, array('placeholder' => 'Enter Banner Description'));
   print "<br/><br/>";
   $options = ['placeholder' => 'Enter content of your banner'];
   print $html->textarea('bannerContent', $text = '', $cols = 82, $rows = 5, $options);
   print "<br/><br/>";
   print $html->submit('createBanner', 'Create Banner', array('type' => 'button', 'size' => 'tiny', 'color' => 'green', 'align' => 'left floated'));
   print "<br/><br/>";
   print $html->hidden_text('username', $_SESSION['username']);
   print $html->endform();
   print $html->endmodalcontent();
   print $html->endmodal();
}

function viewBannerModal()
{
   global $global;

   $html = $global['html'];
   $db = $global['db'];

   $bannerID = intval($_GET['bannerID']);

   $statement = "SELECT * FROM lan_switch_banner WHERE id = $bannerID";
   $queryResult = $db->query($statement);

   print $html->startmodal("Banner Detailed Info", true);

   print $html->startmodalcontent();
   print '<br>';
   print '<b>Authored by: ' . $queryResult[$bannerID]['created_by'] . ' on ' .
      $queryResult[$bannerID]['created_at'] . '<br><br>';

   if ($queryResult[$bannerID]['edited_by']) {
      print 'Edited by ' . $queryResult[$bannerID]['edited_by'] . ' on ' . $queryResult[$bannerID]['edited_at'] . '.<br><br>';
   }

   print 'Banner Code:  ' . $queryResult[$bannerID]['banner_code'] . '<br><br>';
   print 'Banner Description :  ' . $queryResult[$bannerID]['banner_desc'] . '<br><br>';
   print 'Banner Content :  <pre>' . $queryResult[$bannerID]['banner_content'] . '</pre><br></b>';

   print $html->endmodalcontent();

   print '<br><br>';

   print $html->endmodal();
}

function deleteBannerModal()
{
   global $global;

   $html = $global['html'];

   print $html->startmodal("Delete Banner", true); //true property brings a close icon next to the div box, but you can close it without the 'x' icon. It is just not as intuitive for somee poeple
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->warning_message('Are you sure want to delete this banner', $title = 'Permanent Delete');
   print $html->submit('deleteBanner', 'Yes, I want to delete it', array('type' => 'button', 'size' => 'tiny', 'color' => 'red', 'align' => 'left floated'));
   print "<br/><br/>";
   print $html->hidden_text('username', $_SESSION['username']);
   print $html->endform();
   print $html->endmodalcontent();
   print $html->endmodal();

}

function editBannerModal()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];
   $db = $global['db'];

   $bannerID = intval($_GET['bannerID']);

   $statement = "SELECT * FROM lan_switch_banner WHERE id = $bannerID";
   $queryResult = $db->query($statement);

   $bannerCode = $queryResult[$bannerID]['banner_code'];
   $bannerDesc = $queryResult[$bannerID]['banner_desc'];
   $bannerContent = $queryResult[$bannerID]['banner_content'];

   print $html->startmodal("Edit Banner", true); //true property brings a close icon next to the div box, but you can close it without the 'x' icon. It is just not as intuitive for somee poeple
   print $html->startmodalcontent();
   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->input_text('bannerCode', $bannerCode, 2048, 80, array('placeholder' => ''));
   print "<br/><br/>";
   print $html->input_text('bannerDesc', $bannerDesc, 2048, 80, array('placeholder' => ''));
   print "<br/><br/>";
   print $html->textarea('bannerContent', $text = $bannerContent, $cols = 82, $rows = 5, $options);
   print "<br/><br/>";
   print $html->submit('editBanner', 'Edit Banner', array('type' => 'button', 'size' => 'tiny', 'color' => 'green', 'align' => 'left floated'));
   print "<br/><br/>";
   print $html->hidden_text('username', $_SESSION['username']);
   print $html->endform();
   print $html->endmodalcontent();
   print $html->endmodal();
}

function sectionSyncWithCatalystCenter()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];

   $tab = $global['tab'];
   $adminSection = $global['adminSection'];

   $selectRegion = array('Select Region', 'APAC', 'EMEA', 'NALA');
   $region = $input->get('region', 'alphanumeric');

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->select('region', $selectRegion, $region);
   print $html->hidden_text('tab', 'Admin');
   print $html->hidden_text('adminSection', $adminSection);
   print $html->submit('Run', 'Run', array('color' => 'grey'));
   print $html->endform();

   $region = $input->get('region', 'alphanumeric');

   if ($region && !preg_match('/select/i', $region)) {
      $script = "update-lan-switch -r " . strtolower($region);
      print "<br><br>";
      $func->loadstart("Gathering data from $region Catalist Center. Please wait...");
      exec("/opt/epic/core/gdc/run/$script", $output, $result);
      $func->loadfinish();
      print $html->success_message("Data has been synchronized.", "SUCCESS");
   }
}

function sectionUpdateADGroup()
{
   global $global;

   $input = $global['input'];
   $func = $global['func'];
   $html = $global['html'];
   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];
   $tab = $global['tab'];
   $db = $global['db'];
   $userinfo = $global['userinfo'];
   $adGroups = $global['adGroups'];
   $adminSection = $global['adminSection'];

   $adGroup = $input->get('adGroup', 'all');

   //Update db logs before checking for ad trainer
   if ($_POST['update_ad_group_trainer']) {
      update_ad_group_trainer();
   }

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->select('adGroup', $adGroups, $adGroup);
   print $html->hidden_text('tab', 'Admin');
   print $html->hidden_text('adminSection', $adminSection);
   print $html->submit('SelectGroup', 'Select Group', array('color' => 'grey'));
   print "<br><br>";
   print $html->endform();

   print $func->loadstart("Gathering AD group details, please wait...", "searchGroup");

   $adTrainer = getADTrainer() ? true : false;

   if ($adGroup && !preg_match('/select/i', $adgroup)) {
      $filter = "(&(distinguishedname=$adGroup))";
      $groupInfo = $ldap->search($adbasedn, $filter);

      unset($groupInfo[0]['member']['count']);

      print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
      print $html->input_text('adObject', $adObject, 60, 60, array('placeholder' => 'Search AD Object'));
      print $html->hidden_text('tab', 'Admin');
      print $html->hidden_text('adminSection', $adminSection);
      print $html->hidden_text('adGroup', $adGroup);

      if (preg_match("/Managed LAN Service Local IT/i", $adGroup)) {
         if ($adTrainer == true) {
            print $html->submit('addUserToGroup', 'Add to Group', array('color' => 'green'));
            print $html->submit('removeUserFromGroup', 'Remove from Group', array('color' => 'red'));
         } else {
            print $html->submit('addUserToGroup', 'Add to Group', array('color' => 'gray', 'disable' => true));
            print $html->submit('removeUserFromGroup', 'Remove from Group', array('color' => 'gray', 'disable' => true));
         }
      } else {
         print $html->submit('addUserToGroup', 'Add to Group', array('color' => 'green'));
         print $html->submit('removeUserFromGroup', 'Remove from Group', array('color' => 'red'));
      }

      print $html->hidden_text('update_ad_group_trainer', '');
      print $html->endform();

      if (preg_match("/Managed LAN Service Local IT/i", $adGroup)) {
         //Checkbox to validate if user has performed training, and who was its teacher
         $checkboxProperties = [
            'checked' => $adTrainer ? true : false,
         ];

         echo "<div style='margin-top: 5px;'>";
         echo $html->semantic_ui_checkbox('check_update_ad_training', true, 'EPIC Training completed', $checkboxProperties);
         echo "</div>";

         //Modal to ask who trained whom
         //Remove modal from class to make sure the element doesn't pop up, and use js to add the modal to class, then pop it whenever you need it
         //Just search by id in js code, and you'll see
         //To obtain clientWidth, element must still be rendered, use visibility trait instead of display
         //clientWidth is necessary to make input fields, etc, responsive to container size
         echo "<div id='update_ad_group_training_modal' class='ui' style='visibility: hidden; display: block;'>";
         echo "<i class='close icon'></i>";
         echo "<div class='header'>";
         echo "Who performed your training?";
         echo "</div>";
         echo "<div class='content' style='width: 100%; margin-bottom: 30px;'>";
         echo "<div style='width: 100%; margin-bottom: 20px;'>";
         echo "<div style='width: 100%; margin-bottom: 5px;'>";
         echo $html->input_text('training_modal_ad_trainer', "", 60, 117, array('placeholder' => 'Search Trainer', 'class' => 'ui fluid'));
         echo "</div>";
         echo "</div>";
         echo $html->info_message('A system generated email notification will be sent to Managed LAN Service Engineering for each user that will be added', 'Read Me');
         echo "<div>";
         echo "<div style='margin-bottom: 10px'>";
         echo $html->submit('training_modal_specify_trainer_btn', 'Continue', ['disable' => true, 'color' => 'gray', 'align' => 'right floated']);
         echo "</div>";
         echo "</div>";
         echo "</div>";
         echo "</div>";
      }

      print "<br>";

      $addUserToGroup = $input->get('addUserToGroup', 'all');
      $removeUserFromGroup = $input->get('removeUserFromGroup', 'all');
      $adObject = $input->get('adObject', 'all');

      if ($adObject && ($addUserToGroup || $removeUserFromGroup)) {
         if ($addUserToGroup) {
            addObjectToGroup($adObject, $adGroup);
         }
         if ($removeUserFromGroup) {
            removeObjectFromGroup($adObject, $adGroup);
         }

         $filter = "(&(distinguishedname=$adGroup))";
         $groupInfo = $ldap->search($adbasedn, $filter);

         unset($groupInfo[0]['member']['count']);
      }

      foreach ($groupInfo[0]['member'] as $member) {
         $members[] = "JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) = '" . $db->escape_string($member) . "'";
      }

      $searchMembers = implode(' OR ', $members);

      $query = "SELECT JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) AS adObject, tool_log_date AS updated, tool_log_user AS updated_by " .
         "FROM tool_changelog WHERE tool_log_tool like 'lan.manager' " .
         "AND  tool_log_desc LIKE '%addUserToADGroup%' AND ($searchMembers) AND tool_log_desc LIKE '%$adGroup%' AND tool_log_desc LIKE '%Success%' " .
         "ORDER BY tool_log_date desc";

      $logs = $db->query($query);

      foreach ($groupInfo[0]['member'] as $member) {
         // Get the display name of the member 
         $filter = "(&(distinguishedname=$member))";
         $memberDetails = $ldap->search($adbasedn, $filter);

         $item['Display Name'] = $memberDetails[0]['displayname'][0];
         $item['Member DN'] = $member;

         $item['Added by'] = $logs[$member]['updated_by'] ? $logs[$member]['updated_by'] : 'No data';
         $item['Added'] = $logs[$member]['updated'] ? $logs[$member]['updated'] : 'No data';

         $items[] = $item;
      }

      $columns = [
         'Display Name' => 'Display Name',
         'Member DN' => 'Member DN',
         'Added By' => 'Added by',
         'Added' => 'Added'
      ];

      $table_options = [
         'datatable' => 1,
         'table.id' => 'update_ad_table'
      ];

      if ($items) {
         print $html->table($items, $columns, $table_options);
      } else {
         print $html->warning_message("This group has no members", "Note");
      }
   }

   print $func->loadfinish("searchGroup");
}

function is_adm_account($username)
{
   global $global;

   $ldap = $global['ldap'];
   $adbasedn = $global['adbasedn'];

   $attributes = ['extensionattribute4'];
   $filter = "(&(sAMAccountName=$username))";
   $user_attributes = $ldap->search($adbasedn, $filter, $attributes);

   $attrib_4 = $user_attributes[0]['extensionattribute4'][0];

   if ($attrib_4 == "Administrator") {
      return true;
   }

   return false;
}

function addObjectToGroup($adObject, $group)
{

   global $global;

   $db = $global['db'];
   $ldap = $global['ldap'];
   $func = $global['func'];
   $html = $global['html'];
   $adbasedn = $global['adbasedn'];
   $userinfo = $global['userinfo'];
   $user_name = $userinfo['username'];
   $emails = $global['emails'];
   $adGroups = $global['adGroups'];

   $filter = "(&(objectClass=user)(|(displayname=$adObject)(sAMAccountName=$adObject)(cn=$adObject)" . "(mail=$adObject)))";

   $adObjectDetails = $ldap->search($adbasedn, $filter);
   $samaccountname = $adObjectDetails[0]['samaccountname'][0];
   $membership = array_values($adObjectDetails[0]['memberof']);

   $normalAccountName = getNormalAccountName($ldap, $adbasedn, $samaccountname);


   $is_managed_local_it_group = preg_match("/Managed LAN Service Local IT/i", $group) ? true : false;
   $is_adm_account = is_adm_account($samaccountname);

   if ($is_managed_local_it_group) {
      $adTrainer = getADTrainer();
   }

   if (in_array($group, $membership)) {
      print $html->warning_message("User $adObject ($samaccountname) is already a member of  $group", "No Change");
   } else {
      if ($is_managed_local_it_group && !$is_adm_account) {
         print $html->error_message("Could not add $adObject ($samaccountname) to $group", "ERROR");
         return 0;
      }

      $result = $ldap->add_user_to_group($adbasedn, $samaccountname, $group);

      $logData = array(
         'action' => 'addUserToADGroup',
         'object' => $adObjectDetails[0]['dn'],
         'data' => ['group' => $group],
         'status' => $result ? "Success" : "Failed",
      );

      $adGroup = $adGroups[$group];

      $query = "update lan_management_rbac_location set deleted = 0 where user_name = '$samaccountname' and ad_group = '$adGroup'";
      $updateResult = $db->execute($query);


      if ($normalAccountName) {

         $updateAdmAccountLogs = updateAdmAccountWithNormalAccountProperties($ldap, $adbasedn, $samaccountname, $normalAccountName);

         $logData['data']['updateAdmAccount'] = $updateAdmAccountLogs;

         $func->loguser($db, $samaccountname, 'mirror.adattributes');
      }

      $func->logchange($db, json_encode($logData));

      $to = implode(',', $emails);

      if ($is_managed_local_it_group) {
         $adTrainer = getADTrainer();
      }

      $to = implode(',', $emails);

      $emailBody = '';

      if ($result) {
         print $html->success_message("Added $adObject ($samaccountname)  to $group", "SUCCESS");
         $emailBody = "User $user_name added $adObject ($samaccountname) to Managed LAN Service Local IT group.<br><br>User $adObject was trained by $adTrainer.";
      } else {
         $emailBody = "User $user_name could not be added $adObject ($samaccountname) to Managed LAN Service Local IT group.<br><br>User $adObject was trained by $adTrainer.";
         print $html->error_message("Could not add $adObject ($samaccountname) to $group", "ERROR");
      }

      if ($is_managed_local_it_group) {
         send_notification_email($to, "epic.lan.manager@emerson.com", "EPIC LAN Management | Managed LAN Service Local IT AD Group", $emailBody);
      }
   }
}

/**
 * Retrieves the normal user account name (msExchExtensionAttribute26) 
 * for the given admin account.
 *
 * @param LDAP   $ldap           LDAP connection instance.
 * @param string $adbasedn       Active Directory base DN.
 * @param string $samAccountName Admin account name.
 *
 * @return string|null Normal account name or null if not found.
 */
function getNormalAccountName($ldap, $adbasedn, $samAccountName)
{
   global $ldap, $adbasedn;

   $normalAccountName = $ldap->get_exchextattr_from_samaccountname($adbasedn, $samAccountName, 26);

   return $normalAccountName;
}

/**
 * Updates an admin AD account with msExchExtensionAttribute28–30 values 
 * copied from the corresponding normal user account.
 * @param LDAP $ldap
 * @param string $adbasedn
 * @param string $admAccountName
 * @param string $normalAccountName
 * @return array
 */
function updateAdmAccountWithNormalAccountProperties($ldap, $adbasedn, $admAccountName, $normalAccountName)
{
   global $func;

   if (!$normalAccountName) {
      return ['error' => "No normal account detected. Possibly incorrect adm account $admAccountName."];
   }

   $filter = "(&(objectClass=user)(|(samaccountname=$normalAccountName)))";
   $attributes = ['msExchExtensionAttribute28', 'msExchExtensionAttribute29', 'msExchExtensionAttribute30'];
   $adSearchResult = $ldap->search($adbasedn, $filter, $attributes);

   if ($adSearchResult['count'] !== 1) {
      return ['error' => "Found no accounts or more than one account. Possible incorrect normal account $normalAccountName."];
   }

   $areaCode = $adSearchResult[0]['msexchextensionattribute28'][0] ?? null;
   $siteCode = $adSearchResult[0]['msexchextensionattribute29'][0] ?? null;
   $locationCode = $adSearchResult[0]['msexchextensionattribute30'][0] ?? null;

   $entry = [];
   $updatingExistingAttributes = [];

   if ($areaCode) {
      $entry['msExchExtensionAttribute28'] = [0 => $areaCode];
      $updatingExistingAttributes[] = 28;
   }

   if ($siteCode) {
      $entry['msExchExtensionAttribute29'] = [0 => $siteCode];
      $updatingExistingAttributes[] = 29;
   }

   if ($locationCode) {
      $entry['msExchExtensionAttribute30'] = [0 => $locationCode];
      $updatingExistingAttributes[] = 30;
   }

   $updateAccountResult = $ldap->update_ad_user($adbasedn, $admAccountName, $entry);

   $msExchExtAttributes = 'msExchExtensionAttribute ' . implode(',', $updatingExistingAttributes);

   if ($updateAccountResult['result'] === true) {
      return ['success' => "Successfully updated $msExchExtAttributes for $admAccountName with account $normalAccountName data."];
   } else {
      return ['error' => "Failed to update $msExchExtAttributes for $admAccountName with account $normalAccountName data."];
   }
}

function update_ad_group_trainer()
{
   global $global;

   $func = $global['func'];
   $db = $global['db'];
   $userinfo = $global['userinfo'];

   $user_name = $userinfo['user_name'];

   $sql_req = "SELECT tool_log_id FROM tool_changelog WHERE tool_log_desc LIKE '%specifyUpdateADGroupTrainer%' AND tool_log_tool = 'lan.manager' AND tool_log_user = '$user_name'";
   $sql_res = $db->query($sql_req);

   if (empty($sql_res)) {
      $trainer = htmlspecialchars($_POST['update_ad_group_trainer'], ENT_QUOTES, 'UTF-8');

      $log_data = [
         'action' => 'specifyUpdateADGroupTrainer',
         'object' => $trainer,
         'data' => "Was trained by $trainer",
         'status' => 'Success'
      ];

      $func->logchange($db, json_encode($log_data));
   }
}

function send_notification_email($to, $from, $subject, $body)
{
   global $global;

   $mail = $global['mail'];
   $func = $global['func'];
   $db = $global['db'];
   $tool_name = $global['tool_name'];
   $emails = $global['emails'];

   $headers = [
      'MIME-Version' => '1.0',
      'Content-type' => "text/html; charset='us-ascii'",
      "BCC" => "teodor.maxim@emerson.com,manuela.rotar@emerson.com"
   ];

   $status = $mail->send($to, $from, $subject, $body, $headers);

   if (empty($status)) {
      $func->logtool($db, 3, "Could not notify $from", $tool_name);
   }
}

function getADTrainer()
{
   global $global;

   $db = $global['db'];
   $userinfo = $global['userinfo'];
   $userName = $userinfo['username'];
   $func = $global['func'];

   $dbQuery = "SELECT JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) as object FROM tool_changelog WHERE tool_log_desc LIKE '%specifyUpdateADGroupTrainer%' AND tool_log_tool = 'lan.manager' AND tool_log_user = '$userName' order by tool_log_date desc limit 1";
   $dbResult = $db->query($dbQuery);

   $adTrainer = '';

   foreach ($dbResult as $key => $value) {
      $adTrainer = $key;
   }

   return $adTrainer;
}

function removeObjectFromGroup($adObject, $group)
{
   global $global;

   $db = $global['db'];
   $ldap = $global['ldap'];
   $func = $global['func'];
   $html = $global['html'];
   $adbasedn = $global['adbasedn'];
   $userinfo = $global['userinfo'];
   $user_name = $userinfo['username'];
   $emails = $global['emails'];
   $adGroups = $global['adGroups'];

   $is_managed_local_it_group = preg_match("/Managed LAN Service Local IT/i", $group) ? true : false;

   $filter = "(&(objectClass=user)(|(displayname=$adObject)(sAMAccountName=$adObject)(cn=$adObject)" .
      "(mail=$adObject)))";

   $adObjectDetails = $ldap->search($adbasedn, $filter);
   $samaccountname = $adObjectDetails[0]['samaccountname'][0];

   $result = $ldap->remove_user_from_group($adbasedn, $samaccountname, $group);

   $logData = array(
      'action' => 'removeUserFromADGroup',
      'object' => $adObjectDetails[0]['dn'],
      'data' => $group,
      'status' => $result ? "Success" : "Failed",
   );

   $func->logchange($db, json_encode($logData));

   if ($is_managed_local_it_group) {
      $adTrainer = getADTrainer();
   }

   $to = implode(',', $emails);

   $emailBody = '';

   $adGroup = $adGroups[$group];

   $query = "update lan_management_rbac_location set deleted = 1 where user_name = '$samaccountname' and ad_group = '$adGroup'";
   $updateResult = $db->execute($query);

   if ($result) {

      print $html->success_message("Removed $adObject ($samaccountname) from $group", "SUCCESS");
      $emailBody = "User $user_name removed $adObject ($samaccountname) from Managed LAN Service Local IT group.<br><br>User $adObject was trained by $adTrainer.";
   } else {
      $emailBody = "User $user_name could not remove $adObject ($samaccountname) from Managed LAN Service Local IT group.<br><br>User $user_name was trained by $adTrainer.";
      print $html->error_message("Could not remove $adObject ($samaccountname) from $group", "ERROR");
   }

   if ($is_managed_local_it_group) {
      send_notification_email($to, "epic.lan.manager@emerson.com", "EPIC LAN Management | Managed LAN Service Local IT AD Group", $emailBody);
   }
}

function showLog()
{
   global $global;

   $db = $global['db'];
   $input = $global['input'];
   $html = $global['html'];

   $columns = array(
      'Item(s)' => 'object',
      'Action' => 'action',
      'Details' => 'data',
      'Status' => 'status',
      'Time/Date' => 'updated',
      'Updated By' => 'updated_by',
   );

   $days = $input->get('days', 'numeric') ? $input->get('days', 'numeric') : 3;

   $dayslist = array(
      '3' => '3 days',
      '5' => '5 days',
      '7' => '7 days',
      '14' => '14 days',
      '30' => '30 days',
      '60' => '60 days',
      '90' => '90 days',
   );

   print $html->startform(array('method' => 'post', 'action' => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)));
   print $html->select('days', $dayslist, $days);
   print $html->hidden_text('tab', 'Log');
   print $html->submit('Submit', 'Go', array('color' => 'grey', ));
   print $html->endform();

   $topts = array('datatable' => 1, 'table.id' => 'logs');

   $query = "SELECT tool_log_date as updated, tool_log_user as updated_by, " .
      "JSON_UNQUOTE(json_extract(tool_log_desc,'$.object')) as object, " .
      "JSON_UNQUOTE(json_extract(tool_log_desc,'$.data')) as data, " .
      "JSON_UNQUOTE(json_extract(tool_log_desc,'$.action')) as action, " .
      "JSON_UNQUOTE(json_extract(tool_log_desc,'$.status')) as status " .
      "FROM tool_changelog WHERE tool_log_tool like 'lan.manager' " .
      "AND tool_log_date > now() - interval $days day " .
      "ORDER BY tool_log_date desc";

   $results = $db->query($query);

   print "<br>";

   print $html->table($results, $columns, $topts);

}

function collectInterfaceData($devicename)
{

   global $global;

   $snmp = $global['snmp'];
   $switches = $global['switches'];
   $oids = $global['oids'];

   $devip = $switches[$devicename]['ipaddress'];

   $return = array();

   foreach ($oids['int'] as $oidname => $oidvalue) {

      $result[$oidname] = $snmp->walk($devip, $oidvalue);

      foreach ($result[$oidname] as $key => $value) {
         $match = explode('.', $key);

         if (preg_match('/cdp|iferrdisable/i', $oidname)) {
            $newkey = $match[count($match) - 2];
         } else {
            $newkey = $match[count($match) - 1];
         }

         if (preg_match('/iftrunkvlan/i', $oidname)) {
            // Value returned for this oid consist of a hex string 
            // "A string of octets containing one bit per VLAN in the
            // management domain on this trunk port. The first octet
            // corresponds to VLANs with VlanIndex values of 0 through 7;
            // the second octet to VLANs 8 through 15; etc. The most
            // significant bit of each octet corresponds to the lowest
            // value VlanIndex in that octet. If the bit corresponding to
            // a VLAN is set to '1', then the local system is enabled for
            // sending and receiving frames on that VLAN; if the bit is set
            // to '0', then the system is disabled from sending and
            // receiving frames on that VLAN"  

            $bytes = unpack("C*", $value);

            foreach ($bytes as $i => $byte) {
               for ($a = 7; $a >= 0; $a--) {
                  $mask = pow(2, $a);

                  if (($byte & $mask) === $mask) {
                     $vlan = ((($i - 1) * 8) + (7 - $a));

                     $return[$newkey][$oidname][] = $vlan;
                  }
               }
            }
            if (count($return[$newkey][$oidname]) > 1000) {
               //This means we have all the vlans on this trunk
               $return[$newkey][$oidname] = array('All VLANs allowed');
            }
         } else {
            $return[$newkey][$oidname] = $value;
         }
      }
   }

   return $return;
}

function collectVlanData($devicename)
{

   global $global;

   $snmp = $global['snmp'];
   $oids = $global['oids'];
   $switches = $global['switches'];

   $devip = $switches[$devicename]['ipaddress'];

   $return = array('remove' => '');

   $result = $snmp->walk($devip, $oids['vlan']);

   foreach ($result as $key => $value) {
      preg_match('/^[\d.]+\K\.\d+$/', $key, $match);

      $newkey = preg_replace('/\./', '', $match[0]);

      if (!preg_match('/default/', $value))
         $return[$newkey] = $newkey . ' ' . $value;
   }

   //$return['remove vlan']= 'Remove VLAN';

   return $return;
}

function abbreviate($interface)
{
   $patterns = array(
      '/loopback/i',
      '/tengigabitethernet/i',
      '/tengige/i',
      '/gigabitethernet/i',
      '/gig /i',
      '/port-channel/i',
      '/fastethernet/i',
      '/fas /i',
      '/ethernet/i',
      '/tunnel/i',
      '/serial/i',
      '/multilink/i'
   );
   $abbrev = array('Lo', 'Te', 'Te', 'Gi', 'Gi', 'Po', 'Fa', 'Fa', 'Eth', 'Tu', 'Se', 'Mu');

   return preg_replace($patterns, $abbrev, $interface);
}

function getSnowLocations($siteId)
{

   global $snow;

   $params = array(
      'u_sitecode' => $siteId,
      'sysparm_fields' => 'name,sys_id,u_correlation_id,city,u_it_world_region,u_region,street,u_sitecode',
      'sysparm_display_value' => 'all'
   );

   $result = $snow->getLocation($params);

   foreach ($result['result'] as $item) {
      $return[] = $item['u_correlation_id']['value'];

   }

   return $return;
}

function getSwitchFromSnow($serialNumber)
{

   global $global;

   $snow = $global['snow'];

   $params = array(
      'serial_number' => $serialNumber,
      'sysparm_fields' => 'name,ip_address,install_status,u_business_criticality,device_type,u_configuration_type,' .
         'u_local_poc,u_location_hours,support_group,location,u_ownership,u_support_remarks',
      'sysparm_display_value' => 'all'
   );

   $result = $snow->getSwitch($params);

   $return = $result['result'][0];

   return $return;
}
//====================================================================

?>