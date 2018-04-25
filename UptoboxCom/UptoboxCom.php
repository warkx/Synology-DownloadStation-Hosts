<?php

/*Auteur : warkx
  Partie premium developpé par : Einsteinium
  Aidé par : Polo.Q, Samzor
  Version : 1.6.4
  Développé le : 25/04/2018
  Description : Support du compte gratuit et premium*/
  
  
class SynoFileHosting
{
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;
    private $CookieValue;
    
    private $ENABLE_DEBUG = FALSE;
    private $FILE_ID;
    private $WAITINGTOKEN_FILE;
  
    private $COOKIE_FILE = '/tmp/uptobox.cookie';
    private $WAITINGTOKEN_FILEPATH = '/tmp/';
    private $LOG_FILE = '/tmp/uptobox.log';
    
    private $LOGIN_URL = 'https://uptobox.com/?op=login';
    private $ACCOUNT_TYPE_URL = 'https://uptobox.com/?op=my_account';
    
    private $WAITINGTOKEN_REGEX = "/waitingToken' value='(.*?)'/i";
    private $FILE_ID_REGEX = '/https?:\/\/uptobox\.com\/(.+)/';
    private $FILE_NAME_REGEX = '/<title>(.+?)<\/title>/si';
    private $FILE_OFFLINE_REGEX = '/The file was deleted|Page not found/i';
    private $DOWNLOAD_WAIT_REGEX = "/data-remaining-time='(\d*)/i";
    private $FILE_URL_REGEX = '`(https?:\/\/\w+\.uptobox\.com\/dl\/.+?)(?:"|\n|$)`si';
    private $ACCOUNT_TYPE_REGEX = '/Premium\s*member/i';
    private $ERROR_404_URL_REGEX = '/uptobox.com\/404.html/i';
    private $DEBUG_REGEX = '/(https?:\/\/uptobox\.com\/.+)\/debug/i';
    private $ORIGINAL_URL_REGEX = '/https?:\/\/(uptobox\.com\/.+)/i';
    
    private $STRING_COUNT = 'count';
    private $STRING_FNAME = 'fname';
    private $QUERYAGAIN = 1;
    private $WAITING_TIME_DEFAULT = 60;
    
    private $TAB_REQUEST = array('waitingToken' => '');
    
    public function __construct($Url, $Username, $Password, $HostInfo) 
    {
		$this->Username = $Username;
		$this->Password = $Password;
		$this->HostInfo = $HostInfo;
        
        //verifie si le debug est activé avec un "/debug"
        preg_match($this->DEBUG_REGEX, $Url, $debugmatch);
        if(!empty($debugmatch[1]))
        {
            $this->Url = $debugmatch[1];
            $this->ENABLE_DEBUG = TRUE;
        }else
        {
            $this->Url = $Url;
        }
        $this->MakeUrl();
        $this->DebugMessage("URL: ".$this->Url);
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
        if ($ClearCookie && file_exists($this->COOKIE_FILE)) 
        {
            unlink($this->COOKIE_FILE);
        }
        return $ret;
    }
    
    //Lance le telechargement en fonction du type de compte
    public function GetDownloadInfo()
    {
        $ret = false;
        $VerifyRet = $this->Verify(false);
    
        if(USER_IS_PREMIUM == $VerifyRet)
        {
            $ret = $this->DownloadPremium();
      
        }else if(USER_IS_FREE == $VerifyRet)
        {
            $ret = $this->DownloadWaiting(true);
        }else
        {
            $ret = $this->DownloadWaiting(false);
        }
    
        if($ret != false)
        {
            $ret[INFO_NAME] = trim($this->HostInfo[INFO_NAME]);
        }
    
        return $ret;
    }
  
    //Telechargement en mode premium
    private function DownloadPremium()
    {
        $page = false;
        $DownloadInfo = array();
        $page = $this->UrlFilePremium();
        $this->DebugMessage("PAGE_PREMIUM: ".$page);

        if($page == false)
        {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
        }else
        {          
          preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
          if(!empty($filenamematch[1]))
          {
            $DownloadInfo[DOWNLOAD_FILENAME] = trim($filenamematch[1]);
            $this->DebugMessage(trim($filenamematch[1]));
          }
          
          preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
          if(!empty($urlmatch[1]))
          {
            $DownloadInfo[DOWNLOAD_URL] = trim($urlmatch[1]);
            $this->DebugMessage("URL_PREMIUM: ".trim($urlmatch[1]));
          }else
          {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
          }
          
          $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
          $DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
        }
        return $DownloadInfo;
    }
    
    //telechargement en mode gratuit ou sans compte
    private function DownloadWaiting($LoadCookie)
    {
        $DownloadInfo = false;
        $page = $this->DownloadParsePage($LoadCookie);
        $this->DebugMessage("PAGE_FREE: ".$page);
        
        if($page != false)
        {
            //Termine la fonction si le fichier est offline
            preg_match($this->FILE_OFFLINE_REGEX,$page,$errormatch);
            if(isset($errormatch[0]))
            {
                $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            }else
            {
                //verifie s'il faut attendre et si c'est le cas, renvoie le temps d'attente
                $result = $this->VerifyWaitDownload($page);
                if($result != false)
                {
                    $Count = $result[$this->STRING_COUNT];
                    $DownloadInfo[DOWNLOAD_COUNT] = $Count;
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                    
                    $this->DebugMessage("WAITING_FREE: ".$Count);
                }else
                {
                    preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
                    if(!empty($filenamematch[1]))
                    {
                        $DownloadInfo[DOWNLOAD_FILENAME] = trim($filenamematch[1]);
                        $this->DebugMessage("FILENAME_FREE: ".trim($filenamematch[1]));
                    }
                    
                    //clique sur le bouton "Generer le lien" et recupere la vrai URL
                    $page = $this->UrlFileFree($LoadCookie);
                    $this->DebugMessage("PAGE_GENERATE_FREE_: ".$page);

                    preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
                    if(!empty($urlmatch[1]))
                    {
                        $DownloadInfo[DOWNLOAD_URL] = trim($urlmatch[1]);
                        $this->DebugMessage("URL_FREE: ".trim($urlmatch[1]));
                    }else
                    {
                        $DownloadInfo[DOWNLOAD_COUNT] = $this->WAITING_TIME_DEFAULT;
                        $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                    }
                }
                //$DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = false;
            }
            if($LoadCookie == true)
            {
                $DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
            }
        }
        return $DownloadInfo;
    }
        
    private function UrlFileFree($LoadCookie)
    {
        $ret = false;
        $data = $this->TAB_REQUEST;
        $data = http_build_query($data);
        $curl = curl_init();
    
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        if($LoadCookie == true)
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
        }
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_URL, $this->Url);
    
        $header = curl_exec($curl);
        curl_close($curl);

        $ret = $header;
        return $ret;
    }
    
    
    //Renvoie le temps d'attente indiqué sur la page, ou false s'il n'y en a pas
    private function VerifyWaitDownload($page)
    {
        $ret = false;
        
        preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
        if(!empty($waitingmatch[1]))
        {
            $waitingtime = $waitingmatch[1] + 3;
            If($waitingtime == 33)
            {                
                $this->GenerateWaitingTokenPath();
                $waintigtoken = $this->FindWaitingToken();
                
                If($waintigtoken == FALSE)
                {
                    $this->GenerateRequest($page);
                    $ret[$this->STRING_COUNT] = $waitingtime;
                }                
            }else
            {
                $ret[$this->STRING_COUNT] = $waitingtime;
            }
        }
        
        return $ret;
    }
    
    //recherche un waintigtoken sur la page et l'enregistre dans un fichier
    private function GenerateRequest($page)
    {      
        preg_match($this->WAITINGTOKEN_REGEX, $page, $waitingtokenmatch);
        if(!empty($waitingtokenmatch[1]))
        {
            $this->WriteWaintingToken($waitingtokenmatch[1]);
            $this->DebugMessage("WAINTINGTOKEN_FIND_ON_PAGE: ".$waitingtokenmatch[1]);
        }
       
    }
    
    //creer le fichier dans lequel l'id du waitingtoken sera stocké
    private function GenerateWaitingTokenPath()
    {
        preg_match($this->FILE_ID_REGEX, $this->Url, $fileidmatch);
        if(!empty($fileidmatch[1]))
        {
            $this->WAITINGTOKEN_FILE = ($this->WAITINGTOKEN_FILEPATH).($fileidmatch[1]).(".uptobox.token");
            $this->DebugMessage("WAINTINGTOKEN_FILE_CREATED: ".$this->WAITINGTOKEN_FILE);
        }
    }
    
    //cherche un fichier contenant un id de waintigtoken. Renvoie false s'il n'y e a pas et recupere 
    //la valeur s'il y en a une
    private function FindWaitingToken()
    {
        $ret = false;
        If(file_exists($this->WAITINGTOKEN_FILE))
        {
            $ret = file_get_contents($this->WAITINGTOKEN_FILE);
            unlink($this->WAITINGTOKEN_FILE);
            $this->TAB_REQUEST['waitingToken'] = $ret;
            $this->DebugMessage("WAINTINGTOKEN_FIND_ON_NAS: ".$ret);
            $ret = true;
        }
        return $ret;
    }
    
    //ecrit l'id du waitingtoken dans un fichier
    private function WriteWaintingToken($waintigtoken)
    {
        $myfile = fopen($this->WAITINGTOKEN_FILE, "w");
        fwrite($myfile,$waintigtoken);
        fclose($myfile);
    }
    
    //authentifie l'utilisateur sur le site
    private function Login()
    {
        $ret = LOGIN_FAIL;
		$PostData = array('login'=>$this->Username,
                        'password'=>$this->Password,
                        'op'=>'login');
            
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
			$cookieData = file_get_contents ($this->COOKIE_FILE);
			if(strpos($cookieData,'xfss') == true) 
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
            $ret = USER_IS_PREMIUM;
        }else
        {
            $ret = USER_IS_FREE;
        }
        return $ret;
    }
    
    //affiche la page en mode gratuit
    private function DownloadParsePage($LoadCookie)
    {
        $ret = false;
        $curl = curl_init(); 
    
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        if($LoadCookie == true)
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
        }
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); 
        curl_setopt($curl, CURLOPT_URL, $this->Url); 
    
        $ret = curl_exec($curl); 
        $info = curl_getinfo($curl);
        curl_close($curl);

         $this->Url = $info['url'];
        return $ret; 
    }
    
    //renvoie la vrai url du fichier en mode premium. Ou false si elle n'est pa affiché
    private function UrlFilePremium()
    {
        $ret = false;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_URL, $this->Url);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($curl, CURLOPT_HEADER, true);

		$header = curl_exec($curl);
		$info = curl_getinfo($curl);
        curl_close($curl);
    
		$error_code = $info['http_code'];
		
		if ($error_code == 301 || $error_code == 302) 
        { 
			$ret = $info['redirect_url'];
		}
        preg_match($this->ERROR_404_URL_REGEX, $ret, $finderror);
        if(isset($finderror[0]))
        {
            $ret = false;
        }else
        {
            $ret = $header;
        }
		return $ret;
    }
    
    //ecrit un message dans un fichier afin de debug le programme
    private function DebugMessage($texte)
    {
        If($this->ENABLE_DEBUG == TRUE)
        {
            $myfile = fopen($this->LOG_FILE, "a");
            fwrite($myfile,$texte);
            fwrite($myfile,"\n\n");
            fclose($myfile);
        }
    }
    
        /*créé une URL propre qui permettra de l'utiliser correctement
    */
    private function MakeUrl()
    { 
       preg_match($this->ORIGINAL_URL_REGEX, $this->Url, $originalurlmatch);
        if(!empty($originalurlmatch[1]))
        {
            $this->Url = "https://".$originalurlmatch[1];
        }
    }
}
?>