<?php

/*Auteur : warkx
  Version originale Developpé le : 16/04/2015
  Version : 1.5.1
  Développé le : 10/05/2015
  Description : Support des comptes premium et  gratuit
*/ 

/*Declaration des constantes. WX est un préfixe pour eviter d'avoir un conflit
avec les constantes par défaut */

define('WX_LINK', 'link');
define('WX_QUERYAGAIN', 1);
define('WX_NOTQUERYAGAIN', 0);

class SynoFileHosting
{
    private $Url;
    private $ORIGINAL_URL;
    private $HostInfo;
    private $Username;
    private $Password;
    private $CookieValue;
    private $FILEID;
    
    private $COOKIE_FILE = '/tmp/uplea.cookie';
    
    private $GET_LINK_API = 'http://api.uplea.com/api/get-link';
    private $LOGIN_URL = 'http://uplea.com/?lang=en';
    private $ACCOUNT_TYPE_URL = 'http://uplea.com/account';
    
    private $FILEONLINE_REGEX = '`result":{.*?status":"(OK)".*?}`i';
    private $FILENAME_REGEX = '`"filename":"(.*?)"`i';
    private $FILEID_REGEX = '`"code":"(.*?)"`i'; 
    private $FILEURL_REGEX = '`"(https?:\/\/[a-zA-Z0-9]+\.uplea\.com\/[^"]+)`i';
    private $DOWNLOAD_WAIT_REGEX = '/jCountdown\({\s*timeText:(\d+)/i';
    private $FREE_WAIT_REGEX = '`ulCounter\({\'timer\':(\d+)`i';
    private $ACCOUNT_TYPE_REGEX = '`Uplea\s*free\s*member`i';
    private $PREMIUM_REQUIRED_REGEX = '`You need to have a Premium subscription to download this file`i';
    
    private $GET_LINK_API_TAB = array(WX_LINK => '');
    
    public function __construct($Url, $Username, $Password, $HostInfo)
    { 
        $this->Url = $Url;
        $this->ORIGINAL_URL = $Url;
        $this->Username = $Username;
        $this->Password = $Password;
        $this->HostInfo = $HostInfo;
    }

    public function GetDownloadInfo() 
    { 
        $ret = false;
        $page = false;
        $this->GET_LINK_API_TAB[WX_LINK] = $this->Url;
        $page = $this->GetInfos($this->GET_LINK_API_TAB, $this->GET_LINK_API);
        
        if($page != false)
        {
            preg_match($this->FILEONLINE_REGEX, $page, $fileonlinematch);
            if(empty($fileonlinematch[1]))
            {
                $ret[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;

            }else
            {
                preg_match($this->FILEID_REGEX,$page,$fileidmatch);
                if(empty($fileidmatch[1]))
                {
                    $ret[DOWNLOAD_ERROR] = ERR_UPATE_FAIL;                    
                }else
                {
                    $this->FILEID = $fileidmatch[1];
                    $this->Url = $this->MakeUrl();
                    
                    $VerifyRet = $this->Verify(false);
                    if($VerifyRet == USER_IS_PREMIUM)
                    {
                        $ret = $this->DownloadPremium();
                    }else
                    {
                        $ret = $this->DownloadWaiting($VerifyRet);
                    }
                
                    preg_match($this->FILENAME_REGEX,$page,$filenamematch);
                    if(!empty($filenamematch[1]))
                    {
                        $ret[DOWNLOAD_FILENAME] = $filenamematch[1];
                    }
                }
            }
        }else
        {
            $ret[DOWNLOAD_ERROR] = ERR_UPATE_FAIL;
        }
        
        $ret[INFO_NAME] = trim($this->HostInfo[INFO_NAME]);
        
        return $ret; 
    }

    //se connecte et renvoie le type du compte
    public function Verify($ClearCookie)
    {
        $ret = LOGIN_FAIL;
        $this->CookieValue = false;
  
        //si le nom d'utilisateur et le mot de passe sont entré on se connecte
        //renvoie le cookie si la connexion est initialisé
        if(!empty($this->Username) && !empty($this->Password)) 
        {
            $this->CookieValue = $this->Login($this->Username, $this->Password);
        }
        //Verifie le type de compte
        if($this->CookieValue != false) 
        {
            $ret = $this->AccountType($this->Username, $this->Password);
        }
        if (($ClearCookie OR $ret == LOGIN_FAIL) && file_exists($this->COOKIE_FILE)) 
        {
            unlink($this->COOKIE_FILE);
        }
        return $ret;
    }
    
    
    //Telechargement en mode premium
    private function DownloadPremium()
    {
        $ret = false;
        $DownloadInfo = array();
        $ret = $this->UrlFilePremium();
        
        if($ret != false)
        {
            $DownloadInfo[DOWNLOAD_URL] = $ret;
        }else
        {
            $DownloadInfo = $this->DownloadWaiting(USER_IS_PREMIUM);
        }
        return $DownloadInfo;
    }
    
    
    private function DownloadWaiting($VerifyRet)
    {
        $page = false;
        $DownloadInfo = false;
        $VerifyWait = false; 
        
        $page = $this->DownloadPage($this->ORIGINAL_URL);
        $page = $this->DownloadPage($this->Url);
        
        if($page !=false)
        {
            preg_match($this->PREMIUM_REQUIRED_REGEX,$page, $errormatch);
            if(isset($errormatch[0]))
            {
               $DownloadInfo[DOWNLOAD_ERROR] = ERR_REQUIRED_PREMIUM; 
            }else
            {
                if($VerifyRet != USER_IS_PREMIUM)
                {
                   $VerifyWait = $this->VerifyWaitDownload($page); 
                }
            
                if($VerifyWait != false)
                {
                    $DownloadInfo[DOWNLOAD_COUNT] = $VerifyWait;
                }
                
                preg_match($this->FILEURL_REGEX, $page, $realurlmatch);
                if(!empty($realurlmatch[1]))
                {
                    $DownloadInfo[DOWNLOAD_URL] = $realurlmatch[1];
                    if($VerifyWait != false)
                    {
                        $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = WX_NOTQUERYAGAIN;
                    }
                }else
                {
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = WX_QUERYAGAIN;
                }
            }            
        }else
        {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
        }
        
        return $DownloadInfo;
    }
    
    
    private function DownloadPage($strUrl)
    {
        $ret = false;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($curl, CURLOPT_URL, $strUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_FILE);	
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$ret = curl_exec($curl);
        curl_close($curl);
        return $ret;
    }
    
    private function Login()
    {
        $ret = false;
        $LoginInfo = false;
		$PostData = array('login' => $this->Username,
                          'password' =>$this->Password,
                          'remember' =>1,
                          'login-form' =>'');
            
		$queryUrl = $this->LOGIN_URL;
		$PostData = http_build_query($PostData);
        
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $PostData);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $queryUrl);
		$LoginInfo = curl_exec($curl);
		curl_close($curl);
		
		if ($LoginInfo != false && file_exists($this->COOKIE_FILE)) 
        {
            $CookieData = file_get_contents($this->COOKIE_FILE);
			if(stripos($CookieData, '#HttpOnly_uplea.com'))
            {
                $ret = true;
			}else 
            {
                $ret = false;
            }
		}
        
		return $ret;
    }
    
    //renvoie premium si le compte est premium sinon concidere qu'il est gratuit
    private function AccountType()
    {
        $ret = false;
        $curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $this->ACCOUNT_TYPE_URL);
		$page = curl_exec($curl);
		curl_close($curl);
    
        preg_match($this->ACCOUNT_TYPE_REGEX,$page,$accouttypematch);
        if(isset($accouttypematch[0]))
        {
            $ret = USER_IS_FREE;
        }else
        {
            $ret = USER_IS_PREMIUM;
        }
        return $ret;
    }
    
    private function VerifyWaitDownload($page)
    {
        $ret = false;
        
        preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
        if(!empty($waitingmatch[1]))
        {
            $ret = $waitingmatch[1] + 10;
        }else
        {
            preg_match($this->FREE_WAIT_REGEX, $page, $waitingmatch);
            if(!empty($waitingmatch[1]))
            {
                $ret = $waitingmatch[1] +3;
            }
            
        }
        return $ret;
    }
    
    private function MakeUrl()
    {
        $ret = 'http://uplea.com/step/'.$this->FILEID.'/3?lang=en';
        return $ret;
    }
    
    private function UrlFilePremium()
    {
        $ret = false;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_URL, $this->Url);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($curl, CURLOPT_HEADER, true);

		$header = curl_exec($curl);
		$info = curl_getinfo($curl);
        curl_close($curl);
    
		$error_code = $info['http_code'];
		
		if ($error_code == 301 || $error_code == 302) 
        { 
            $url = $info['redirect_url'];
            $ret = $url;
		}
		return $ret;
    }
    
    
    //recupere les informations des urls
    private function GetInfos($tab, $linkAPI)
    {
        $ret = false;
        $data = $tab;
        $data = http_build_query($data);
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_URL, $linkAPI);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        return $ret;
    }
}
?>