<?php

/**
 * Process a page request
 * @package framework
 * @subpackage dispatch
 */

trait Hm_Dispatch_Redirect {

    /**
     * Redirect after an HTTP POST form
     * @param Hm_Request $request request object
     * @param object $session session object
     * @param Hm_Module_Exec $mod_exec module manager object
     * @return boolean
     */
    private function post_redirect($request, $session, $mod_exec) {
        if (!empty($request->post) && $request->type == 'HTTP') {
            $msgs = Hm_Msgs::get();
            if (!empty($msgs)) {
                $session->secure_cookie($request, 'hm_msgs', base64_encode(json_encode($msgs)), 0);
            }
            $session->end();
            if (array_key_exists('redirect_url', $mod_exec->handler_response) && $mod_exec->handler_response['redirect_url']) {
                Hm_Dispatch::page_redirect($mod_exec->handler_response['redirect_url']);
            }
            if (array_key_exists('REQUEST_URI', $request->server)) {
                Hm_Dispatch::page_redirect($this->validate_request_uri($request->server['REQUEST_URI']));
            }
            return true;
        }
        return false;
    }

    /**
     * Validate a request uri
     * @param string $uri URI to validate
     * @return string
     */
    public function validate_request_uri($uri) {
        if ($uri === '') {
            return '/';
        }
        $parts = parse_url($uri);
        if (array_key_exists('scheme', $parts)) {
            return '/';
        }
        if ($parts === false || !array_key_exists('path', $parts) || strpos($parts['path'], '..') !== false) {
            return '/';
        }
        return $uri;
    }


    /**
     * Redirect the page after a POST form is submitted and forward any user notices
     * @param Hm_Request $request request object
     * @param Hm_Module_Exec $mod_exec module manager object
     * @param object $session session object
     * @return string|false
     */
    public function check_for_redirect($request, $mod_exec, $session) {
        if (array_key_exists('no_redirect', $mod_exec->handler_response) && $mod_exec->handler_response['no_redirect']) {
            return 'noredirect';
        }
        if ($this->post_redirect($request, $session, $mod_exec)) {
            return 'redirect';
        }
        elseif (array_key_exists('hm_msgs', $request->cookie) && trim($request->cookie['hm_msgs'])) {
            $msgs = @json_decode(base64_decode($request->cookie['hm_msgs']), true);
            if (is_array($msgs)) {
                array_walk($msgs, function($v) { Hm_Msgs::add($v); });
            }
            $session->secure_cookie($request, 'hm_msgs', false, time()-3600);
            return 'msg_forward';
        }
        return false;
    }

    /**
     * Perform an HTTP redirect
     * @param string $url url to redirect to
     * @param int $status current HTTP status
     * @return void
     */
    static public function page_redirect($url, $status=false) {
        if (DEBUG_MODE) {
            Hm_Debug::add(sprintf('Redirecting to %s', $url));
            Hm_Debug::load_page_stats();
            Hm_Debug::show();
        }
        if ($status == 303) {
            Hm_Debug::add('Redirect loop found');
            Hm_Functions::cease('Redirect loop discovered');
        }
        Hm_Functions::header('HTTP/1.1 303 Found');
        Hm_Functions::header('Location: '.$url);
        return Hm_Functions::cease();
    }

}

/**
 * Page request router that ties all the framework peices together
 */
class Hm_Dispatch {

    use Hm_Dispatch_Redirect;

    public $site_config;
    public $request;
    public $session;
    public $module_exec;
    public $page;

    /**
     * Setup object needed to process a request
     * @param object $config site configuration
     */
    public function __construct($config) {

        /* get the site config defined in the hm3.rc file */
        $this->site_config = $config;

        /* setup a session handler, but don't actually start a session yet */
        $this->session = setup_session($this->site_config); 

        /* instantiate the module runner */
        $this->module_exec = new Hm_Module_Exec($this->site_config);

        /* process request input using the white-lists defined in modules */
        $this->request = new Hm_Request($this->module_exec->filters, $config);

        /* do it */
        $this->process_request();
    }

    /**
     * Process a request
     * @return void
     */
    private function process_request() {

        /* get the request identifier */
        $this->get_page($this->module_exec->filters, $this->request);

        /* load up the module requirements */
        $this->module_exec->load_module_sets($this->page);

        /* run handler modules to process input and perform actions */
        $this->module_exec->run_handler_modules($this->request, $this->session, $this->page);

        /* check to see if a handler module told us to redirect the browser */
        $this->check_for_redirect($this->request, $this->module_exec, $this->session);

        /* save and close a session if one is open */
        $active_session = $this->save_session();

        /* run the output formatting modules */
        $this->module_exec->run_output_modules($this->request, $active_session, $this->page,
            $this->module_exec->handler_response);

        /* output content to the browser */
        $this->render_output();
    }

    /**
     * Close and save the session
     * @return bool true if the session is active
     */
    private function save_session() {
        $res = $this->session->is_active();
        Hm_Page_Cache::save($this->session);
        $this->session->end();
        return $res;
    }

    /**
     * Format and send the request output to the browser
     * @return void
     */
    private function render_output() {
        $formatter = new $this->request->format($this->site_config);
        $renderer = new Hm_Output_HTTP();
        $content = $formatter->content($this->module_exec->output_response, $this->request->allowed_output);
        $renderer->send_response($content, $this->module_exec->output_data);
    }

    /**
     * Get a list of valid pages
     * @param array $filters list of filters
     * @param return array
     */
    private function get_pages($filters) {
        $pages = array();
        if (array_key_exists('allowed_pages', $filters)) {
            $pages = $filters['allowed_pages'];
        }
        return $pages;
    }

    /**
     * Check for a valid ajax request
     * @param Hm_Request $request request details
     * @param array $filters list of filters
     * @return boolean
     */
    public function validate_ajax_request($request, $filters) {
        if (array_key_exists('hm_ajax_hook', $request->post)) {
            if (in_array($request->post['hm_ajax_hook'], $this->get_pages($filters), true)) {
                return true;
            }
            else {
                Hm_Functions::cease(json_encode(array('status' => 'not callable')));;
            }
        }
        return false;
    }

    /**
     * Determine the page id
     * @param array $filters list of filters
     * @param Hm_Request $request request details
     * @return void
     */
    public function get_page($filters, $request) {
        $this->page = 'notfound';
        if ($request->type == 'AJAX' && $this->validate_ajax_request($request, $filters)) {
            $this->page = $request->post['hm_ajax_hook'];
        }
        elseif (array_key_exists('page', $request->get) && in_array($request->get['page'], $this->get_pages($filters), true)) {
            $this->page = $request->get['page'];
        }
        elseif (!array_key_exists('page', $request->get)) {
            $this->page = 'home';
        }
        $this->module_exec->page = $this->page;
        Hm_Debug::add('Page ID: '.$this->page);
    }

    /**
     * Check to see if PHP is configured properly
     * @return bool
     */
    static public function is_php_setup() {
        return
            (float) substr(phpversion(), 0, 3) >= 5.4 &&
            Hm_Functions::function_exists('mb_strpos') &&
            Hm_Functions::function_exists('curl_exec') &&
            Hm_Functions::function_exists('openssl_random_pseudo_bytes') &&
            Hm_Functions::class_exists('PDO');

    }
}
