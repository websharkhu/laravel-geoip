<?php namespace Torann\GeoIP;

use GeoIp2\Database\Reader;
use GeoIp2\WebService\Client;

use Illuminate\Config\Repository;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\Cache;

class GeoIP {

    /**
     * The session store.
     *
     * @var \Illuminate\Session\Store
     */
    protected $session;

    /**
     * Illuminate config repository instance.
     *
     * @var \Illuminate\Config\Repository
     */
    protected $config;

    /**
     * Remote Machine IP address.
     *
     * @var float
     */
    protected $remote_ip = null;

    /**
     * Location data.
     *
     * @var array
     */
    protected $location = null;

    /**
     * Reserved IP address.
     *
     * @var array
     */
    protected $reserved_ips = array (
        array('0.0.0.0','2.255.255.255'),
        array('10.0.0.0','10.255.255.255'),
        array('127.0.0.0','127.255.255.255'),
        array('169.254.0.0','169.254.255.255'),
        array('172.16.0.0','172.31.255.255'),
        array('192.0.2.0','192.0.2.255'),
        array('192.168.0.0','192.168.255.255'),
        array('255.255.255.0','255.255.255.255')
    );

    /**
     * Default Location data.
     *
     * @var array
     */
    protected $default_location = array (
        "ip"            => "127.0.0.0",
        "isoCode"       => "US",
        "country"       => "United States",
        "city"          => "New Haven",
        "state"         => "CT",
        "postal_code"       => "06510",
        "lat"           => 41.31,
        "lon"           => -72.92,
        "timezone"      => "America/New_York",
        "continent"     => "NA",
        "default"           => true
    );

    /**
     * Cached IPs.
     *
     * @var \Illuminate\Config\Repository
     */
    protected $ip_subvisions;

    /**
     * Create a new GeoIP instance.
     *
     * @param  \Illuminate\Config\Repository  $config
     * @param  \Illuminate\Session\Store  $session
     * @return void
     */
    public function __construct(Repository $config, SessionStore $session)
    {
        $this->config = $config;
        $this->session = $session;

        $this->remote_ip = $this->default_location['ip'] = $this->getClientIP();
    }

    /**
     * Save location data in the session.
     *
     * @return void
     */
    function saveLocation()
    {
        $this->session->set('geoip-location', $this->location);
    }

    /**
     * Get location from IP.
     *
     * @param  string $ip Optional
     * @return array
     */
    function getLocation( $ip = null )
    {

        // Get location data
        $this->location = $this->find( $ip );

        // Save user's location
        /*
        if( $ip === null ) {
            $this->saveLocation();
        }
        */

        return $this->location;
    }

    /**
     * Find location from IP.
     *
     * @param  string $ip Optional
     * @return array
     */
    private function find( $ip = null )
    {
        // Check Session
        /*
        if ( $ip === null && $position = $this->session->get('geoip-location') )
        {
            // TODO: Remove default check on 2/28/14
            if(isset($position['default']) && $position['ip'] === $this->remote_ip) {
                return $position;
            }
        }
        */

        // If IP not set, user remote IP
        if ( $ip === null ) {
            $ip = $this->remote_ip;
        }

        // If IP is cached, no need to geocode again
        $this->ip_subvisions = array();
        if (Cache::has('ip_subvisions')) {
            $this->ip_subvisions = Cache::get('ip_subvisions');
        } 
        
        if ( isset($this->ip_subvisions[ $ip ]) ) {
            return array(
                "ip"            => $ip,
                "subdivisions"  => $this->ip_subvisions[ $ip ]['subdivisions']
            );
        } 

        // Check if the ip is not local or empty
        if( $this->checkIp( $ip ) ) {

            // Call default service
            $service = 'locate_'.$this->config->get('geoip::service');

            return $this->$service($ip);
        }

        return $this->default_location;
    }

    /**
     * Maxmind Service.
     *
     * @param  string $ip
     * @return array
     */
    private function locate_maxmind( $ip )
    {
        $settings = $this->config->get('geoip::maxmind');

        if($settings['type'] === 'web_service') {
            $maxmind = new Client($settings['user_id'], $settings['license_key']);
        }
        else {
            $maxmind = new Reader(app_path().'/database/maxmind/GeoIP2-City-Europe.mmdb');
        }

        $record = $maxmind->city($ip);

        // Convert subdivisions to simple arrays
        $subdivisions = array();
        if ( !empty($record->subdivisions) ) {
            foreach ($record->subdivisions as $key => $subdiv) {
                if ( !empty($subdiv->names['en']) ) {
                    $_subdiv_name = mb_strtolower(trim(str_replace('megye', '', $subdiv->names['en'])), 'UTF-8');
                    $subdivisions[ $_subdiv_name ] = $_subdiv_name;
                }      
            }
        }

        $location = array(
            "ip"            => $ip,
            "isoCode"       => $record->country->isoCode,
            "country"       => $record->country->name,
            "city"          => $record->city->name,
            "subdivisions"  => $subdivisions,
            "state"         => $record->mostSpecificSubdivision->isoCode,
            "postal_code"       => $record->postal->code,
            "lat"           => $record->location->latitude,
            "lon"           => $record->location->longitude,
            "timezone"      => $record->location->timeZone,
            "continent"     => $record->continent->code,
            "default"           => false
        );

        // Cache geocoded IPs
        $this->ip_subvisions[ $ip ] = array(
            "ip"            => $ip,
            "subdivisions"  => $subdivisions,
        );
        Cache::put('ip_subvisions', $this->ip_subvisions, 20);  

        unset($record);  

        return $location;
    }

    /**
     * Get the client IP address.
     *
     * @return string
     */
    private function getClientIP()
    {
        if (getenv('HTTP_CLIENT_IP')) {
            $ipaddress = getenv('HTTP_CLIENT_IP');
        }
        else if(getenv('HTTP_X_FORWARDED_FOR')) {
            $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
        }
        else if(getenv('HTTP_X_FORWARDED')) {
            $ipaddress = getenv('HTTP_X_FORWARDED');
        }
        else if(getenv('HTTP_FORWARDED_FOR')) {
            $ipaddress = getenv('HTTP_FORWARDED_FOR');
        }
        else if(getenv('HTTP_FORWARDED')) {
            $ipaddress = getenv('HTTP_FORWARDED');
        }
        else if(getenv('REMOTE_ADDR')) {
            $ipaddress = getenv('REMOTE_ADDR');
        }
        else if( isset($_SERVER['REMOTE_ADDR']) ) {
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        }
        else {
            $ipaddress = '127.0.0.0';
        }

        return $ipaddress;
    }

    /**
     * Checks if the ip is not local or empty.
     *
     * @return bool
     */
    private function checkIp( $ip )
    {
        $longip = ip2long($ip);

        if ( !empty($ip) ) {

            foreach ($this->reserved_ips as $r) {
                $min = ip2long($r[0]);
                $max = ip2long($r[1]);

                if ($longip >= $min && $longip <= $max) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

}
