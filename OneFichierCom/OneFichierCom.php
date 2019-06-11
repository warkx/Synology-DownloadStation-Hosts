<?php

/*
	Auteur : warkx
	Version originale Developpé le : 23/11/2013
	Version : 3.2.2 (modifié par Babasss)
	Développé le : 10/06/2019
	Description : Support du compte gratuit, access, premium et CDN
	
	Packaging by  tar zcf "OneFichierCom(X.X).host" INFO OneFichierCom.php
 */

class SynoFileHosting
{
    private $Url;
    private $HostInfo;
    private $Username;
    private $Password;
    private $FILEID;
    private $ORIGINAL_URL;
    private $ACCOUNT_TYPE;
    private $ADZONE_VALUE = '';
    
    private $ENABLE_DEBUG = TRUE;
    private $ENABLE_DEBUG_HTML = FALSE;
    private $LOG_FILE = '/tmp/1fichier.log';
    private $LOG_FILE_WINNT = 'C:\intel\1fichier.log';
    
    private $COOKIE_PATH = '/tmp/1fichier.cookie';
    private $COOKIE_PATH_WINNT = 'C:\intel\1fichier.cookie';
    
    private $CHECKLINK_URL_REQ = 'https://1fichier.com/check_links.pl';
    
    private $FILEID_REGEX = '`https?:\/\/1fichier\.com\/\?([a-zA-Z0-9]+)\/?`i';
    private $FILEID_OLD_REGEX = '`https?:\/\/([a-z0-9A-Z]+)\.1fichier\.com\/?`i';
    private $FILE_OFFLINE_REGEX = '`BAD LINK|NOT FOUND`i';
    private $DOWNLOAD_WAIT_REGEX = '`You must wait (\d+) minutes`i';
    private $PREMIUM_REAL_URL_REGEX = '`https?:\/\/[a-z0-9]+-[a-z0-9]+\.1fichier\.com\/[a-z0-9]+`i';
    private $FREE_REAL_URL_REGEX = '`href=\"(https?:\/\/[a-z0-9]+-[a-z0-9]+\.1fichier\.com\/[a-z0-9]+)\"?`i';
    private $DEBUG_REGEX = '/(https?:\/\/1fichier\.com\/.+)\/debug/i';
    private $ADZONE_REGEX = '`name="adzone" value="(.+?)"`i';
    
    private $PREMIUM_TYPE_REGEX = '`(^[0-9]{2}+)`i';
    
    private $WAITING_TIME_DEFAULT = 300;
    private $QUERYAGAIN = 1;
	
	private $MIN_CDN_GB = 5;
	private $CDN_FR_REGEX='`Votre compte a ([0-9.]+) Go`i';
    private $CDN_EN_REGEX='`Your account have ([0-9.]+) GB`i';
	private $CDN_CHECKBOX_REGEX='<input type="checkbox" checked="checked" name="own_credit">';
    
    public function __construct($Url, $Username, $Password, $HostInfo)
    {
        $this->DebugMessage("\n");
		$this->DebugMessage($this->debug_datetime() . " | DEBUG construct function");
        
        $this->Url = $Url;
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
    }
    
	private function debug_datetime ()
	{
		$now = DateTime::createFromFormat('U.u', microtime(true));
		return $now->format('Y-m-d H:i:s.u');
	}
	
    //fonction a executer pour recuperer les informations d'un fichier en fonction d'un lien
    public function GetDownloadInfo()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG GetDownloadInfo function");
        
        $ret = false;
        
        //verifie le type de compte
        $this->ACCOUNT_TYPE = $this->Verify(false);
        
        //Recupere l'id, s'il nest pas bon, renvoie NON PRIS EN CHARGE
        $GetFILEIDRet = $this->GetFILEID($this->Url);
        if ($GetFILEIDRet == false)
        {
            $ret[DOWNLOAD_ERROR] = ERR_NOT_SUPPORT_TYPE;
        }else
        {
            //Créé l'url en fonction du type de compte
            $this->MakeUrl();
            
            /*verifie si le lien est valide, si c'est le cas
             le nom du fichier est récupéré, sinon c'est false
             */
            $LinkInfo = $this->CheckLink($this->ORIGINAL_URL);
            
			$this->DebugMessage($this->debug_datetime() . " | DEBUG GetDownloadInfo LinkInfo: ".$LinkInfo);
			
            //Renvoie que le fichier n'existe pas si le lien est obsolète
            if($LinkInfo == false)
            {
                $ret[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            }else
            {
                //en fonction du type de compte, lance la fonction correspondante
                if($this->ACCOUNT_TYPE == USER_IS_PREMIUM)
                {
                    $ret = $this->DownloadPremium();
                }else
                {
                    $ret = $this->DownloadWaiting();
                }
				
				//$ret[ACCOUNT_TYPE] = $this->ACCOUNT_TYPE;
                
                /*Si les fonctions précedentes ont retourné un tableau avec des informations,
                 on y ajoute le nom du fichier aisi que les INFO_NAME (permet de mettre play/pause).
                 si aucun info n'a été retourné on renvoie fichier inexistant
                 */
                if($ret != false)
                {
                    $ret[DOWNLOAD_FILENAME] = $LinkInfo;
                    $ret[INFO_NAME] = trim($this->HostInfo[INFO_NAME]);
                }else
                {
                    $ret[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
                }
            }
        }
		
		$this->DebugMessage($this->debug_datetime() . " | DEBUG GetDownloadInfo InfoName: ".$ret[INFO_NAME]);
		
        return $ret;
    }
    
    //verifie le type de compte entré
    public function Verify($ClearCookie)
    {
		$this->DebugMessage($this->debug_datetime() . " | DEBUG Verify function");

        $ret = LOGIN_FAIL;
        
        if(!empty($this->Username) && !empty($this->Password))
        {
            $ret = $this->TypeAccount($this->Username, $this->Password);
        }
		else
		{
			$this->DebugMessage($this->debug_datetime() . " | DEBUG No username and password => LOGIN_FAIL");
		}
		
        return $ret;
    }
    
    private function DownloadPremium()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPremium function");

        $page = false;
        $DownloadInfo = false;
        
        $page = $this->DownloadPageWithAuth();
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPremium HTML: ".$page);
        
        //Si aucune page n'est retourné, renvoie false
        if($page != false)
        {
            $DownloadInfo = array();
            
            /*Divise le résultat de la page pour recuperer la vrai URL.
             s'il n'est pas trouvé, renvoie ERREUR
             */
            $result = explode(';', $page);
            $realUrl = $result[0];
            
            preg_match($this->PREMIUM_REAL_URL_REGEX,$realUrl,$urlmatch);
            
            if(!empty($urlmatch[0]))
            {
                $DownloadInfo[DOWNLOAD_URL] = $realUrl;
                $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPremium URL_PREMIUM: ".$realUrl);
                $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = TRUE;
            }else
            {
                $page = $this->UrlFilePremiumWithDownloadMenu();
                
                preg_match($this->PREMIUM_REAL_URL_REGEX,$page,$urlmatch);
                if(!empty($urlmatch[0]))
                {
                    $DownloadInfo[DOWNLOAD_URL] = $urlmatch[0];
                    $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPremium URL_PREMIUM: ".$urlmatch[0]);
                    $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = TRUE;
                }else
                {
                    $DownloadInfo[DOWNLOAD_ERROR] = ERR_UPATE_FAIL;
                }
            }
        }
        return $DownloadInfo;
    }
    
    private function DownloadWaiting()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting function");
        
        $DownloadInfo = false;
        $page = false;
        
        if($this->ACCOUNT_TYPE != LOGIN_FAIL)
        {
            $page = $this->DownloadPageWithAuth();
        }else
        {
            $page = $this->DownloadPage($this->Url);
        }
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting HTML: ".$page);
        
        $this->GenerateRequest($page);
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting ADZONE_VALUE: ".$this->ADZONE_VALUE);
        
        //Si aucune page n'est retourné, renvoie false
        if($page != false)
        {
            //si un temps d'attente est detecté sur la page, renvoie le temps à attendre
            $result = $this->VerifyWaitDownload($page);
            if($result != false)
            {
                $DownloadInfo[DOWNLOAD_COUNT] = $result['COUNT'];
                $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting WAITING_FREE: ".$result['COUNT']);
                $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
            }else
            {
                /*Genere le clic sur le bouton Download et tente de récuperer la vrai URL.
                 si la vrai URL n'est pas retourné, on attends un temps par défaut car la page
                 précédente n'indiquait pas qu'il fallait attendre
                 */
                $page = false;
                $URLFinded = false;
                
                if($this->ACCOUNT_TYPE != LOGIN_FAIL)
                {
                    $page = $this->UrlFileWithFreeAccount();
                }else
                {
                    $page = $this->UrlFileFree($this->Url);
                }
                $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting PAGE_GENERATE_FREE_: ".$page);
                
                if($page != false)
                {
                    preg_match($this->FREE_REAL_URL_REGEX, $page, $realUrl);
                    if(!empty($realUrl[1]))
                    {
                        $DownloadInfo[DOWNLOAD_URL] = $realUrl[1];
                        $this->DebugMessage($this->debug_datetime() . " | URL_FREE: ".$realUrl[1]);
                        //$DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = false;
                        $URLFinded = TRUE;
                    }
                }
                
                if($URLFinded == false)
                {
                    $DownloadInfo[DOWNLOAD_COUNT] = $this->WAITING_TIME_DEFAULT;
                    $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadWaiting WAITING_DEFAULT_TIME: ".$this->WAITING_TIME_DEFAULT);
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                }
            }
        }
        return $DownloadInfo;
    }
    
    //verifie sur la page s'il faut attendre et renvoie ce temps + 10 secondes de marge d'erreur
    private function VerifyWaitDownload($page)
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG VerifyWaitDownload function");
        $this->DebugMessage($this->debug_datetime() . " | DEBUG VerifyWaitDownload HTML: ".$page);
        
        $ret = false;
        preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
        
        if(!empty($waitingmatch[1]))
        {
            $waitingtime = ($waitingmatch[1] *60) + 10;
            $ret['COUNT'] = $waitingtime;
        }
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG VerifyWaitDownload WaitCount: ".$ret['COUNT']);
        
        return $ret;
    }
    
    //telecharge une page en y indiquant une URL
    private function DownloadPage($strUrl, $inputoption = null)
    {
		$this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPage function");
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPage URL: ".$strUrl);
        
        $option = array('CURL_OPTION_FOLLOWLOCATION' => TRUE);
        
        $ret = false;
        
        if (!($inputoption == null))
        {
            $option = array_merge ($option, $inputoption);
        }
        
        $curl = $this->GenerateCurl($strUrl,$option);
		// die('echec : '.$curl);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPage HTML: " . $ret);
        // die('echec : '.$ret);
        return $ret;
    }
    
    //Telecharge la page en se connectant
    private function DownloadPageWithAuth()
    {
		$this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPageWithAuth function");
		
        $ret = false;
        
        $url = $this->Url.'&auth=1';
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPageWithAuth URL: ".$url);
        
        //Permet de recuperer la vrai url directement pour les comptes premium
        if($this->ACCOUNT_TYPE == USER_IS_PREMIUM)
        {
            $url = $url.'&e=1';
        }
        
        $option = array('CURL_OPTION_FOLLOWLOCATION' =>false);
        $curl = $this->GenerateCurl($url,$option);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($curl, CURLOPT_USERPWD, $this->Username.':'.$this->Password);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG DownloadPageWithAuth HTML: ".$ret);
        
        return $ret;
    }
    
    //retourne la page après s'etre connecté en premium et avoir cliqué sur le menu de telechargement
    private function UrlFilePremiumWithDownloadMenu()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFilePremiumWithDownloadMenu function");
		
        $ret = false;
        
        $url = $this->Url.'&auth=1&e=1';
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFilePremiumWithDownloadMenu URL: ".$url);
        
        $data = array('submit'=>'download');
        
        $option = array('CURL_OPTION_POSTDATA' => $data,
            'CURL_OPTION_HEADER' => TRUE,
            'CURL_OPTION_FOLLOWLOCATION' =>false);
        
        $curl = $this->GenerateCurl($url,$option);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($curl, CURLOPT_USERPWD, $this->Username.':'.$this->Password);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFilePremiumWithDownloadMenu HTML: ".$ret);
        
        return $ret;
    }
    
    private function GenerateRequest($page)
    {
		$this->DebugMessage($this->debug_datetime() . " | DEBUG GenerateRequest function");
        $this->DebugMessage($this->debug_datetime() . " | DEBUG GenerateRequest HTML: ".$page);
		
        preg_match($this->ADZONE_REGEX, $page, $adzonematch);
        if(isset($adzonematch[1]))
        {
            $this->ADZONE_VALUE = $adzonematch[1];
        }
    }
    
    //Retourne la page après avoir cliqué sur le bouton et s'etre authentifié en gratuit
    private function UrlFileWithFreeAccount()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileWithFreeAccount function");
		
		$ret = false;
        $url = $this->Url.'&auth=1';
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileWithFreeAccount URL: ".$url);
        
        // $data = array('submit'=>'Access to download', 'adzone'=>$this->ADZONE_VALUE);
        $data = array('submit'=>'Download', 'adzone'=>$this->ADZONE_VALUE);
        
        $option = array('CURL_OPTION_POSTDATA' => $data,
            'CURL_OPTION_FOLLOWLOCATION' =>false);
        
        $curl = $this->GenerateCurl($url,$option);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($curl, CURLOPT_USERPWD, $this->Username.':'.$this->Password);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileWithFreeAccount HTML: ".$ret);
        
        return $ret;
    }
    
    //envoie une requete POST pour generer le clic sur Download et renvoie la vrai url du fichier. ou false si la page ne renvoie rien
    private function UrlFileFree($strUrl)
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileFree function");
		$this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileFree URL: ".$strUrl);
		        
        $ret = false;

		###Babasss
		
		$page_temp = $this->DownloadPage($strUrl);
		
		preg_match ('/"adz" value="([0-9A-z.]*)"/' , $page_temp, $matches);
		$adz = $matches[1];
		
		###Babasss
		
		        // $data = array('submit'=>'Access to download', 'adzone'=>$this->ADZONE_VALUE);
        $data = array('submit'=>'Download', 'adzone'=>$this->ADZONE_VALUE, 'adz'=>$adz);
        
		
        $option = array('CURL_OPTION_POSTDATA' => $data,
            'CURL_OPTION_HEADER' => false);
        
        $curl = $this->GenerateCurl($strUrl,$option);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG UrlFileFree VraiURL: ".$ret);
        
        return $ret;
    }
    
    //verifie si le lien est valide et retourne le nom du fichier si c'est le cas. Renvoie false si ça n'est pas le cas
    private function CheckLink($strUrl)
    {
		$this->DebugMessage($this->debug_datetime() . " | DEBUG CheckLink function");
		
        $ret = false;
        $data = array('links[]'=>$strUrl);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG CheckLink URL: ".$strUrl);
        
        $option = array('CURL_OPTION_POSTDATA' => $data);
        $curl = $this->GenerateCurl($this->CHECKLINK_URL_REQ,$option);
        $page = curl_exec($curl);
        curl_close($curl);
        
        preg_match($this->FILE_OFFLINE_REGEX, $page, $errormatch);
        if(!isset($errormatch[0]))
        {
            $result = explode(';', $page);
            $ret = $result[1];
        }
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG CheckLink FileName: ".$ret);
        
        return $ret;
    }
    
    private function TypeAccount($Username, $Password)
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount function");
		
		$ret = LOGIN_FAIL;
		
		$postData=array('mail'=>$Username,'pass'=>$Password,'lt'=>'on','purge'=>'on','valider'=>'Envoyer');
        
        //Generation d'un cookie à la première connexion$cookiepath
        if (strpos(PHP_OS, "WINNT") === false)
        {
            $cookiepath = $this->COOKIE_PATH;
        }
        else
        {
            $cookiepath = $this->COOKIE_PATH_WINNT;
        }
        
        $option = array('CURL_OPTION_COOKIE' => TRUE, 'CURL_OPTION_SAVECOOKIEFILE' => $cookiepath, 'CURL_OPTION_POSTDATA'=>$postData);
        $queryUrl = 'https://1fichier.com/login.pl';
        $page = $this->DownloadPage($queryUrl,$option);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount LoginURL: ".$queryUrl);
        $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount IndexHTML: ".$page);
        
        $pos = strpos($page, $Username);
		
		
        $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount Pos Username: ".$pos);
        
        if($pos > 0)
        {
            
            $option = array('CURL_OPTION_COOKIE' => TRUE, 'CURL_OPTION_LOADCOOKIEFILE' => $cookiepath);
            $queryUrl = 'https://1fichier.com/console/index.pl';
            $page = $this->DownloadPage($queryUrl, $option);
            
            $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount IndexURL: ".$queryUrl);
            $this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount IndexHTML: ".$page);
            
            // if((strpos($page, "Premium") > 0) || (strpos($page, "Access") > 0))
			if( (strpos($page, "Premium offer Account") > 0) || (strpos($page, "Compte offre Premium") > 0) || (strpos($page, "Access offer Account") > 0) || (strpos($page, "Compte offre Access") > 0) )
            {
                
				$ret = USER_IS_PREMIUM;
            }
            else
            {
                
                if((strpos($page, "Free") > 0) || (strpos($page, "Gratuit") > 0))
                {
                    if ($this->HaveCDN()===true) $ret = USER_IS_PREMIUM;
					else $ret = USER_IS_FREE;
                }
            }
			
			switch($ret)
			{
				case USER_IS_FREE:
					$this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount Type : FREE");
					break;
				case USER_IS_PREMIUM:
					$this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount Type : PREMIUM");
					break;
				case LOGIN_FAIL:
					$this->DebugMessage($this->debug_datetime() . " | DEBUG TypeAccount Type : Login Fail");
					break;
			}
        }
        
        return $ret;
    }
    
    //extrait l'identifiant du fichier de l'url entré
    private function GetFILEID($strUrl)
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG GetFILEID function");
		
		$ret = false;
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG GetFILEID SourceURL: ".$strUrl);
        
        /*si l'url est sous le nouveau format elle renvoie son ID
         si elle est sous l'ancien format, renvoie également son ID
         sinon renvoie faux
         */
        preg_match($this->FILEID_REGEX, $strUrl, $FILEIDMatch);
        
        if(!empty($FILEIDMatch[1]))
        {
            $this->FILEID = $FILEIDMatch[1];
            $ret = TRUE;
        }else
        {
            preg_match($this->FILEID_OLD_REGEX, $strUrl, $FILEIDMatch);
            if(!empty($FILEIDMatch[1]))
            {
                $this->FILEID = $FILEIDMatch[1];
                $ret = TRUE;
            }
        }
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG GetFILEID FileID: ".$ret);
        
        return $ret;
    }
    
    /*créé une URL propre qui permettra de l'utiliser correctement
     */
    private function MakeUrl()
    {
        $this->DebugMessage($this->debug_datetime() . " | DEBUG MakeUrl function");
		
		//créé une url d'origine propre
        $this->ORIGINAL_URL = 'https://1fichier.com/?'.$this->FILEID;
        $this->Url = $this->ORIGINAL_URL.'&lg=en';
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG MakeUrl ORIGINAL_URL: ".$this->ORIGINAL_URL);
        $this->DebugMessage($this->debug_datetime() . " | DEBUG MakeUrl URL: ".$this->Url);
    }
    
    //ecrit un message dans un fichier afin de debug le programme
    private function DebugMessage($texte)
    {
        If($this->ENABLE_DEBUG == TRUE)
        {
            
            $pos = strpos($texte, "HTML");
              
            if (strpos(PHP_OS, "WINNT") === false)
            {
                $logfile = $this->LOG_FILE;
            }
            else 
            {
                $logfile = $this->LOG_FILE_WINNT;
            }
            
            if ($pos === false || $this->ENABLE_DEBUG_HTML == true)
            {
                
                $myfile = fopen($logfile, "a");
                fwrite($myfile,$texte);
                fwrite($myfile,"\n");
                fclose($myfile);
            }
        }
    }
	
	//Fonction pour detecter si le compte possede des CDN
	private function HaveCDN()
	{
		$this->DebugMessage($this->debug_datetime() . " | DEBUG HaveCDN function");
		
		$creditCDN=0;
		
        if (strpos(PHP_OS, "WINNT") === false)
        {
            $cookiepath = $this->COOKIE_PATH;
        }
        else
        {
            $cookiepath = $this->COOKIE_PATH_WINNT;
        }
        
        $option = array('CURL_OPTION_COOKIE' => TRUE, 'CURL_OPTION_LOADCOOKIEFILE' => $cookiepath);
        $queryUrl = 'https://1fichier.com/console/params.pl';
        $page = $this->DownloadPage($queryUrl,$option);
        
        $this->DebugMessage($this->debug_datetime() . " | DEBUG HaveCDN LoginURL: ".$queryUrl);
        $this->DebugMessage($this->debug_datetime() . " | DEBUG HaveCDN IndexHTML: ".$page);
		
		//Obtient la quantité de CDN
        preg_match($this->CDN_FR_REGEX, $page, $stringArrayFRCreditCDN);
        preg_match($this->CDN_EN_REGEX, $page, $stringArrayENCreditCDN);
		if(!empty($stringArrayFRCreditCDN[1]))
		{
			$creditCDN=floatval($stringArrayFRCreditCDN[1]);
		}
		else if(!empty($stringArrayENCreditCDN[1]))
		{
			$creditCDN=floatval($stringArrayENCreditCDN[1]);
		}
		
		$this->DebugMessage($this->debug_datetime() . " | DEBUG HaveCDN CDN credit : ".$creditCDN);
		
		//Verifie si la case des CDN est coché
		$checkedCdnBox=preg_match($this->CDN_CHECKBOX_REGEX,$page);
		if($checkedCdnBox) $this->DebugMessage($this->debug_datetime() . " | DEBUG CDN Checkbox is checked");
		else $this->DebugMessage($this->debug_datetime() . " | DEBUG CDN Checkbox is NOT checked");
		
		if(($creditCDN>=$MIN_CDN_GB)&&$checkedCdnBox) return TRUE;
		else return FALSE;
		
	}

	private function GenerateCurl($Url, $Option=NULL)
	{
		$ret = FALSE;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, DOWNLOAD_TIMEOUT);
		curl_setopt($curl, CURLOPT_TIMEOUT, DOWNLOAD_TIMEOUT);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		if (NULL != $Option) {
			if (!empty($Option['CURL_OPTION_POSTDATA'])) {
				$PostData = http_build_query($Option['CURL_OPTION_POSTDATA']);
				curl_setopt($curl, CURLOPT_POST, TRUE);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $PostData);
			}
			if (!empty($Option['CURL_OPTION_COOKIE'])) {
				curl_setopt($curl, CURLOPT_COOKIE, $Option['CURL_OPTION_COOKIE']);
			}
			if (!empty($Option['CURL_OPTION_HTTPHEADER'])) {
				curl_setopt($curl, CURLOPT_HTTPHEADER, $Option['CURL_OPTION_HTTPHEADER']);
			}
			if (!empty($Option['CURL_OPTION_SAVECOOKIEFILE'])) {
				curl_setopt($curl, CURLOPT_COOKIEJAR, $Option['CURL_OPTION_SAVECOOKIEFILE']);
			}
			if (!empty($Option['CURL_OPTION_LOADCOOKIEFILE'])) {
				curl_setopt($curl, CURLOPT_COOKIEFILE, $Option['CURL_OPTION_LOADCOOKIEFILE']);
			}
			if (!empty($Option['CURL_OPTION_FOLLOWLOCATION']) && TRUE == $Option['CURL_OPTION_FOLLOWLOCATION']) {
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
			}
			if (!empty($Option['CURL_OPTION_HEADER'])&& TRUE == $Option['CURL_OPTION_HEADER']) {
				curl_setopt($curl, CURLOPT_HEADER, TRUE);
			}
		}
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $Url);
		$ret = $curl;
		return $ret;
	}
}
?>
