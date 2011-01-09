<?php
/**
 * DooWebApp class file.
 *
 * @author Leng Sheng Hong <darkredz@gmail.com>
 * @link http://www.doophp.com/
 * @copyright Copyright &copy; 2009 Leng Sheng Hong
 * @license http://www.doophp.com/license
 */


/**
 * DooWebApp is the global context that processed user's requests.
 *
 * <p>It manages the controllers in MVC pattern, handling URI requests 404 not found, redirection, etc.</p>
 *
 * <p>This class is tightly coupled with DooUriRouter.</p>
 *
 * @author Leng Sheng Hong <darkredz@gmail.com>
 * @version $Id: DooWebApp.php 1000 2009-07-7 18:27:22
 * @package doo.app
 * @since 1.0
 */
class DooWebApp{
    /**
     * @var array routes defined in <i>routes.conf.php</i>
     */
    public $route;

    /**
     * Main function to run the web application
     */
    public function run(){
        $this->throwHeader( $this->route_to() );
    }

    /**
     * Handles the routing process.
     * Auto routing, sub folder, subdomain, sub folder on subdomain are supported.
     * It can be used with or without the <i>index.php</i> in the URI
     * @return mixed HTTP status code such as 404 or URL for redirection
     */
    public function route_to(){
        Doo::loadCore('uri/DooUriRouter');
        $router = new DooUriRouter;
        $routeRs = $router->execute($this->route,Doo::conf()->SUBFOLDER);

        if($routeRs[0]!=NULL && $routeRs[1]!=NULL){
            //dispatch, call Controller class
            require_once(Doo::conf()->BASE_PATH ."controller/DooController.php");
            if($routeRs[0][0]!='[')
                require_once(Doo::conf()->SITE_PATH . Doo::conf()->PROTECTED_FOLDER . "controller/{$routeRs[0]}.php");
            else{
                $moduleParts = explode(']', $routeRs[0]);
                $moduleName = substr($moduleParts[0],1);
                require_once(Doo::conf()->SITE_PATH . Doo::conf()->PROTECTED_FOLDER . 'module/'. $moduleName .'/controller/'.$moduleParts[1].'.php');

                //set class name
                $routeRs[0] = $moduleParts[1];
                Doo::conf()->PROTECTED_FOLDER_ORI = Doo::conf()->PROTECTED_FOLDER;
                Doo::conf()->PROTECTED_FOLDER = Doo::conf()->PROTECTED_FOLDER_ORI . 'module/'.$moduleName.'/';
            }

            if(strpos($routeRs[0], '/')!==FALSE){
                $clsname = explode('/', $routeRs[0]);
                $routeRs[0] = $clsname[ sizeof($clsname)-1 ];
            }

            //if defined class name, use the class name to create the Controller object
            $clsnameDefined = (sizeof($routeRs)===4);
            if($clsnameDefined)
                $controller = new $routeRs[3];
            else
                $controller = new $routeRs[0];
            $controller->params = $routeRs[2];

            if(isset($controller->params['__extension'])){
                $controller->extension = $controller->params['__extension'];
                unset($controller->params['__extension']);
            }
			if(isset($controller->params['__routematch'])){
                $controller->routematch = $controller->params['__routematch'];
                unset($controller->params['__routematch']);
            }

            if($_SERVER['REQUEST_METHOD']==='PUT')
                $controller->init_put_vars();

            //before run, normally used for ACL auth
            if($clsnameDefined){
                if($rs = $controller->beforeRun($routeRs[3], $routeRs[1])){
                    return $rs;
                }
            }else{
                if($rs = $controller->beforeRun($routeRs[0], $routeRs[1])){
                    return $rs;
                }
            }
			
			$routeRs = $controller->$routeRs[1]();			
            $controller->afterRun($routeRs);		
            return $routeRs;
        }
        //if auto route is on, then auto search Controller->method if route not defined by user
        else if(Doo::conf()->AUTOROUTE){

            list($controller_name, $method_name, $params, $moduleName )= $router->auto_connect(Doo::conf()->SUBFOLDER);
            
            if(isset($moduleName)){
                Doo::conf()->PROTECTED_FOLDER_ORI = Doo::conf()->PROTECTED_FOLDER;
                Doo::conf()->PROTECTED_FOLDER = Doo::conf()->PROTECTED_FOLDER_ORI . 'module/'.$moduleName.'/';                
            }
            
            $controller_file = Doo::conf()->SITE_PATH . Doo::conf()->PROTECTED_FOLDER . "controller/{$controller_name}.php";
            
            if(file_exists($controller_file)){
                require_once(Doo::conf()->BASE_PATH ."controller/DooController.php");
                require_once($controller_file);

				//check if method name exists in controller
				if( in_array($method_name, get_class_methods($controller_name))===FALSE ){
					$this->throwHeader(404);
					return;
				}

                $controller = new $controller_name;

                if(!$controller->autoroute)
                    $this->throwHeader(404);

                if($params!=NULL)
                    $controller->params = $params;

                if($_SERVER['REQUEST_METHOD']==='PUT')
                    $controller->init_put_vars();

                //before run, normally used for ACL auth
				if($rs = $controller->beforeRun($controller_name, $method_name)){
					return $rs;
				}
				
				$routeRs = $controller->$method_name();					
                $controller->afterRun($routeRs);		
				return $routeRs;
            }
            else{
                $this->throwHeader(404);
            }
        }
        else{
            $this->throwHeader(404);
        }
    }

    /**
     * Reroute the URI to an internal route
     * @param string $routeuri route uri to redirect to
     * @param bool $is404 send a 404 status in header
     */
    public function reroute($routeuri, $is404=FALSE){
        if(Doo::conf()->SUBFOLDER!='/')
            $_SERVER['REQUEST_URI'] = substr(Doo::conf()->SUBFOLDER, 0, strlen(Doo::conf()->SUBFOLDER)-1) . $routeuri;
        else
            $_SERVER['REQUEST_URI'] = $routeuri;

        if($is404===TRUE)
            header('HTTP/1.1 404 Not Found');
        $this->route_to();
    }

    /**
     * Process a module.
     *
     * <p>This is similar to rerouting to a Controller. The framework offer 3 ways to process and render a module.</p>
     *
     * <p>Based on a predefined route:</p>
     * <code>
     * # The route is predefined in routes.conf.php
     * # $route['*']['/top/:nav'] = array('MyController', 'renderTop');
     * $data['top'] = Doo::app()->module('/top/banner');
     * </code>
     *
     * <p>Based on Controller name and Action method:</p>
     * <code>
     * Doo::app()->module('MyController', 'renderTop');
     *
     * # If controller is in sub folder
     * Doo::app()->module('folder/MyController', 'renderTop');
     *
     * # Passed in parameter if controller is using $this->param['var']
     * Doo::app()->module('MyController', 'renderTop', array('nav'=>'banner'));
     * </code>
     *
     * <p>If class name is different from controller filename:</p>
     * <code>
     * # filename is index.php, class name is Admin
     * Doo::app()->module(array('index', 'Admin'), 'renderTop');
     *
     * # in a sub folder
     * Doo::app()->module(array('admin/index', 'Admin'), 'renderTop');
     *
     * # with parameters
     * Doo::app()->module(array('admin/index', 'Admin'), 'renderTop', array('nav'=>'banner'));
     * </code>
     *
     * @param string|array $moduleUri URI or Controller name of the module
     * @param string $action Action to be called
     * @param array $params Parameters to be passed in to the Module
     * @return string Output of the module
     */
    public function module($moduleUri, $action=null, $params=null){
        if($moduleUri[0]=='/'){
            if(Doo::conf()->SUBFOLDER!='/')
                $_SERVER['REQUEST_URI'] = substr(Doo::conf()->SUBFOLDER, 0, strlen(Doo::conf()->SUBFOLDER)-1) . $moduleUri;
            else
                $_SERVER['REQUEST_URI'] = $moduleUri;

            ob_start();
            $this->route_to();
            $data = ob_get_contents();
            ob_end_clean();
            return $data;
        }
        //if Controller name passed in:  Doo::app()->module('admin/SomeController', 'login',  array('nav'=>'home'));
        else if(is_string($moduleUri)){
            $controller_name = $moduleUri;
            if(strpos($moduleUri, '/')!==false){
                $arr = explode('/', $moduleUri);
                $controller_name = $arr[sizeof($arr)-1];
            }
            require_once Doo::conf()->SITE_PATH . Doo::conf()->PROTECTED_FOLDER . "controller/$moduleUri.php";
            $controller = new $controller_name;
            $controller->params = $params;
            if($rs = $controller->beforeRun($controller_name, $action)){
                $this->throwHeader( $rs );
                return;
            }
            
            ob_start();
			$rs = $controller->{$action}();
			
            if($controller->autorender===true){
                Doo::conf()->AUTO_VIEW_RENDER_PATH = array(strtolower(substr($controller_name, 0, -10)), strtolower(preg_replace('/(?<=\\w)(?=[A-Z])/','-$1', $action)));
            }
            $controller->afterRun($rs);
            
            $this->throwHeader( $rs );
            
            $data = ob_get_contents();
            ob_end_clean();
            return $data;
        }
        //if array passed in. For controller file name != controller class name.
        //eg. Doo::app()->module(array('admin/Admin', 'AdminController'), 'login',  array('nav'=>'home'));
        else{
            require_once Doo::conf()->SITE_PATH . Doo::conf()->PROTECTED_FOLDER . "controller/{$moduleUri[0]}.php";
            $controller = new $moduleUri[1];
            $controller->params = $params;
            if($rs = $controller->beforeRun($moduleUri[1], $action)){
                $this->throwHeader( $rs );
                return;
            }
            
            ob_start();
			$rs = $controller->{$action}();
			
			if($controller->autorender===true){
                Doo::conf()->AUTO_VIEW_RENDER_PATH = array(strtolower(substr($controller_name, 0, -10)), strtolower(preg_replace('/(?<=\\w)(?=[A-Z])/','-$1', $action)));
            }
            $controller->afterRun($rs);            
            
            $this->throwHeader( $rs );  
            
            $data = ob_get_contents();
            ob_end_clean();
            return $data;
        }
    }

    /**
     * Advanced version of DooWebApp::module().
     *
     * Module rendered using this method is located in SITE_PATH/PROTECTED_FOLDER/module
     *
     * @param string $moduleName Name of the module. Folder name.
     * @param string|array $moduleUri URI or Controller name of the module
     * @param string $action Action to be called
     * @param array $params Parameters to be passed in to the Module
     * @return string Output of the module
     */
    public function getModule($moduleName, $moduleUri, $action=null, $params=null){
        Doo::conf()->PROTECTED_FOLDER_ORI = $tmp = Doo::conf()->PROTECTED_FOLDER;
        Doo::conf()->PROTECTED_FOLDER = $tmp . 'module/'.$moduleName.'/';
        $result = $this->module($moduleUri, $action, $params);
        Doo::conf()->PROTECTED_FOLDER = $tmp;
        Doo::conf()->PROTECTED_FOLDER_ORI = NULL;
        return $result;
    }

    /**
     * Analyze controller return value and send appropriate headers such as 404, 302, 301, redirect to internal routes.
     *
     * <p>It is very SEO friendly but you would need to know the basics of HTTP status code.</p>
     * <p>Automatically handles 404, include error document or redirect to inner route
     * to handle the error based on config <b>ERROR_404_DOCUMENT</b> and <b>ERROR_404_ROUTE</b></p>
     * <p>Controller return value examples:</p>
     * <code>
     * 404                                  #send 404 header
     * array('/internal/route', 404)        #send 404 header & redirect to an internal route
     * 'http://www.google.com'              #redirect to URL. default 302 Found sent
     * array('http://www.google.com',301)   #redirect to URL. forced 301 Moved Permenantly sent
     * array('/hello/sayhi', 'internal')    #redirect internally, 200 OK
     * </code>
     * @param mixed $code
     */
    public function throwHeader($code){
        if(headers_sent()){
            return;
        }
        if($code!=NULL){
            if(is_int($code)){
                if($code===404){
                    //Controller return 404, send 404 header, include file if ERROR_404_DOCUMENT is set by user
                    header('HTTP/1.1 404 Not Found');
                    if(!empty(Doo::conf()->ERROR_404_DOCUMENT)){
                        include Doo::conf()->SITE_PATH . Doo::conf()->ERROR_404_DOCUMENT;
                    }
                    //execute route to handler 404 display if ERROR_404_ROUTE is defined, the route handler shouldn't send any headers or return 404
                    elseif(!empty(Doo::conf()->ERROR_404_ROUTE)){
						$this->reroute(Doo::conf()->ERROR_404_ROUTE, true);
                    }
                    exit;
                }
                //if not 404, just send the header code
                else{
                    DooUriRouter::redirect(NULL,true, $code);
                }
            }
            elseif(is_string($code)){
                //Controller return the redirect location, it sends 302 Found
                DooUriRouter::redirect($code);
            }
            elseif(is_array($code)){
                //Controller return array('/some/routes/here', 'internal')
                if($code[1]=='internal'){
                    $this->reroute($code[0]);
                    exit;
                }
                //Controller return array('http://location.to.redirect', 301)
                elseif($code[1]===404){
                    $this->reroute($code[0],true);
                    exit;
                }
                // if array('http://location.to.redirect', 302), Moved Temporarily is sent before Location:
                elseif($code[1]===302){
                    DooUriRouter::redirect($code[0],true, $code[1], array("HTTP/1.1 302 Moved Temporarily"));
                }
                //else redirect with the http status defined,eg. 307
                else{
                    DooUriRouter::redirect($code[0],true, $code[1]);
                }
            }
        }
    }

    /**
     * To debug variables with DooPHP's diagnostic view
     * @param mixed $var The variable to view in diagnostics.
     */
    public function debug($var){
        throw new DooDebugException($var);
    }

}

