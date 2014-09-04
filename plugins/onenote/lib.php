<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Microsoft OneNote Repository Plugin
 *
 * @package    repository_onenote
 */

defined('MOODLE_INTERNAL') || die();

require_once('onenote_api.php');

/**
 * Microsoft OneNote repository plugin.
 *
 * @package    repository_onenote
 */
class repository_onenote extends repository {
    /** @var microsoft_onenote onenote oauth2 api helper object */
    private $onenote = null;

    const SESSIONKEY = 'onenote_accesstoken';

    /**
     * Constructor
     *
     * @param int $repositoryid repository instance id.
     * @param int|stdClass $context a context id or context object.
     * @param array $options repository options.
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);

        $clientid = get_config('onenote', 'clientid');
        $secret = get_config('onenote', 'secret');
        $returnurl = new moodle_url('/repository/repository_callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('repo_id', $this->id);
        $returnurl->param('sesskey', sesskey());

        $this->onenote = new microsoft_onenote($clientid, $secret, $returnurl);
        $this->check_login();
    }

     /**
     * Checks whether the user is authenticate or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        if ($token = $this->get_access_token()) {
            $this->setAccessToken($token);
            return true;
        }
        return false;
    }

     /**
     * Returns the access token if any.
     *
     * @return string|null access token.
     */
    protected function get_access_token() {
        global $SESSION;
        if (isset($SESSION->{self::SESSIONKEY})) {

            return $SESSION->{self::SESSIONKEY};
        }
        return null;
    }

    /**
     * Store the access token in the session.
     *
     * @param string $token token to store.
     * @return void
     */
    protected function store_access_token($token) {

        global $SESSION;
        $SESSION->{self::SESSIONKEY} = $token;
    }

    /**
     * Callback method during authentication.
     *
     * @return void
     */
    public function callback() {
        if ($code = optional_param('oauth2code', null, PARAM_RAW)) {
            $clientid = get_config('onenote', 'clientid');
            $secret = get_config('onenote', 'secret');
            $returnurl = new moodle_url('/admin/oauth2callback.php');
            $this->store_access_token($this->onenote->getAccessToken($code,$clientid, $secret, $returnurl));
        }
    }

     public function setAccessToken($accessToken) {
        if ($accessToken == null || 'null' == $accessToken) {
          $accessToken = null;
        }
        //self::$auth->setAccessToken($accessToken);
      }

    /**
     * Print the login form, if required
     *
     * @return array of login options
     */
    public function print_login() {
        $url = $this->onenote->get_login_url();

        if ($this->options['ajax']) {
            $popup = new stdClass();
            $popup->type = 'popup';
            $popup->url = $url->out(false);
            return array('login' => array($popup));
        } else {
            echo '<a target="_blank" href="'.$url->out(false).'">'.get_string('login', 'repository').'</a>';
        }
    }

    /**
     * Given a path, and perhaps a search, get a list of sections.
     *
     * See details on {@link http://docs.moodle.org/dev/Repository_plugins}
     *
     * @param string $path identifier for current path
     * @param string $page the page number of section list
     * @return array list of sections including meta information as specified by parent.
     */
    public function get_listing($path='', $page = '') {
        $ret = array();
        $ret['dynload'] = true;
        $ret['nosearch'] = true;
        $ret['manage'] = 'https://onenote.com/';

        $itemslist = $this->onenote->get_items_list($path,$this->get_access_token());
        $ret['list'] = $itemslist;

        // Generate path bar, always start with the plugin name.
        $ret['path']   = array();
        $ret['path'][] = array('name'=> $this->name, 'path'=>'');

        // Now add each level folder.
        $trail = '';
        if (!empty($path)) {
            $parts = explode('/', $path);
            foreach ($parts as $folderid) {
                if (!empty($folderid)) {
                    $trail .= ('/'.$folderid);
                    $ret['path'][] = array('name' => $this->onenote->get_notebook_name($folderid,$this->get_access_token()),
                                           'path' => $trail);
                }
            }
        }

        return $ret;
    }

    /**
     * Downloads a repository section and saves to a path.
     *
     * @param string $id identifier of section
     * @param string $filename to save section as
     * @return array with keys:
     *          path: internal location of the file
     *          url: URL to the source
     */
    public function get_file($id, $filename = '') {
        $path = $this->prepare_file($filename);
        return $this->onenote->download_section($id, $path);
    }

    /**
     * Return names of the options to display in the repository form
     *
     * @return array of option names
     */
    public static function get_type_option_names() {
        return array('clientid', 'secret', 'pluginname');
    }

    /**
     * Setup repistory form.
     *
     * @param moodleform $mform Moodle form (passed by reference)
     * @param string $classname repository class name
     */
    public static function type_config_form($mform, $classname = 'repository') {
        $a = new stdClass;
        $a->callbackurl = microsoft_onenote::callback_url()->out(false);
        $mform->addElement('static', null, '', get_string('oauthinfo', 'repository_onenote', $a));

        parent::type_config_form($mform);
        $strrequired = get_string('required');
        $mform->addElement('text', 'clientid', get_string('clientid', 'repository_onenote'));
        $mform->addElement('text', 'secret', get_string('secret', 'repository_onenote'));
        $mform->addRule('clientid', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->setType('secret', PARAM_RAW_TRIMMED);
    }

    /**
     * Logout from repository instance and return
     * login form.
     *
     * @return page to display
     */
    public function logout() {
        $this->onenote->log_out();
        return $this->print_login();
    }

    /**
     * This repository doesn't support global search.
     *
     * @return bool if supports global search
     */
    public function global_search() {
        return false;
    }

    /**
     * This repoistory supports any filetype.
     *
     * @return string '*' means this repository support any files
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     * This repostiory only supports internal files
     *
     * @return int return type bitmask supported
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }
}
