<?php 

/*Auteur : warkx
  Version originale Developpé le : 23/11/2013
  Version : 2.7.1
  Développé le : 31/12/2015
  Description : Support du compte gratuit et premium*/
  
class SynoFileHosting
{
    private $Url; 
    private $HostInfo;
    private $Username;
    private $Password;
    private $FILEID;
    private $ORIGINAL_URL;
    private $ACCOUNT_TYPE;
   
    private $CHECKLINK_URL_REQ = 'https://1fichier.com/check_links.pl';
    
    private $FILEID_REGEX = '`https?:\/\/1fichier\.com\/\?([a-zA-Z0-9]+)\/?`i';
    private $FILEID_OLD_REGEX = '`https?:\/\/([a-z0-9A-Z]+)\.1fichier\.com\/?`i';
    private $FILE_OFFLINE_REGEX = '`BAD LINK|NOT FOUND`i';
    private $DOWNLOAD_WAIT_REGEX = '`You must wait (\d+) minutes`i';
    private $PREMIUM_REAL_URL_REGEX = '`1fichier\.com`i';
    private $FREE_REAL_URL_REGEX = '`href=\"(https?:\/\/[a-z0-9]+-[a-z0-9]+.1fichier.com\/[a-z0-9]+)\"?`i';
    
    private $WAITING_TIME_DEFAULT = 300;
    private $QUERYAGAIN = 1;
  
    public function __construct($Url, $Username, $Password, $HostInfo)
    { 
        $this->Url = $Url;
        $this->Username = $Username;
        $this->Password = $Password;
        $this->HostInfo = $HostInfo;
    }
  
    //fonction a executer pour recuperer les informations d'un fichier en fonction d'un lien
    public function GetDownloadInfo() 
    { 
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
                
                /*Si les fonctions précedente on retourné un tableau avec des informations,
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
        return $ret; 
    }
  
    //verifie le type de compte entré
    public function Verify($ClearCookie)
    {
        $ret = LOGIN_FAIL;
  
        if(!empty($this->Username) && !empty($this->Password)) 
        {
            $ret = $this->TypeAccount($this->Username, $this->Password);
        }
        return $ret;
    }
  
    private function DownloadPremium()
    {
        $page = false;
        $DownloadInfo = false;
    
        $page = $this->DownloadPageWithAuth();
        
        //Si aucune page n'est retourné, renvoie false
        if($page != false)
        {
            $RESULTURL = 0;
            $DownloadInfo = array();
            
            /*Divise le résultat de la page pour recuperer la vrai URL.
            s'il n'est pas trouvé, renvoie ERREUR
            */
            $result = explode(';', $page);
            
            preg_match($this->PREMIUM_REAL_URL_REGEX,$result[$RESULTURL],$urlmatch);
            if(isset($urlmatch[0]))
            {
                $DownloadInfo[DOWNLOAD_URL] = $result[$RESULTURL];        
            }else
            {
                $DownloadInfo[DOWNLOAD_ERROR] = ERR_UPATE_FAIL;
            }
        }
        return $DownloadInfo;
    }
  
    private function DownloadWaiting()
    {
        $DownloadInfo = false;
        $page = false;
        
        if($this->ACCOUNT_TYPE != LOGIN_FAIL)
        {
            $page = $this->DownloadPageWithAuth();
        }else
        {
            $page = $this->DownloadPage($this->Url);
        }
        
        
        //Si aucune page n'est retourné, renvoie false
        if($page != false)
        {
            //si un temps d'attente est detecté sur la page, renvoie le temps à attendre
            $result = $this->VerifyWaitDownload($page);
            if($result != false)
            {
                $DownloadInfo[DOWNLOAD_COUNT] = $result['COUNT'];
                $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
            }else
            {
                /*Genere le clic sur le bouton Download et tente de récuperer la vrai URL.
                si la vrai URL n'est pas retourné, on attends un temps par défaut car la page
                précédente n'indiquait pas qu'il fallait attendre
                */
                $page = false;
                $URLFinded = false;
                
                $page = $this->UrlFileFree($this->Url);
                
                if($page != false)
                {
                    preg_match($this->FREE_REAL_URL_REGEX, $page, $realUrl);
                    if(!empty($realUrl[1]))
                    {
                        $DownloadInfo[DOWNLOAD_URL] = $realUrl[1];
                        $URLFinded = true;
                    }
                }
                
                if($URLFinded == false)
                {
                    $DownloadInfo[DOWNLOAD_COUNT] = $this->WAITING_TIME_DEFAULT;
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                }
            }
        }
        return $DownloadInfo;
    }
  
    //verifie sur la page s'il faut attendre et renvoie ce temps + 10 secondes de marge d'erreur
    private function VerifyWaitDownload($page)
    {
        $ret = false;
        preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
    
        if(isset($waitingmatch[1]))
        {
            $waitingtime = ($waitingmatch[1] *60) + 10;
            $ret['COUNT'] = $waitingtime;
        }
        return $ret;
    }
  
    //telecharge une page en y indiquant une URL
    private function DownloadPage($strUrl)
    {
        $ret = false;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($curl, CURLOPT_URL, $strUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$ret = curl_exec($curl);
        curl_close($curl);
        return $ret;
    }
    
    //Telecharge la page en se connectant
    private function DownloadPageWithAuth()
    {
        $ret = false;
        
        $url = $this->Url.'&auth=1';
        
        //Permet de recuperer la vrai url directement pour les comptes premium
        if($this->ACCOUNT_TYPE == USER_IS_PREMIUM)
        {
            $url = $url.'&e=1';
        }
       
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($curl, CURLOPT_USERPWD, $this->Username.':'.$this->Password);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        return $ret;
    }
  
  //envoie une requete POST pour generer le clic sur Download et renvoie la vrai url du fichier. ou false si la page ne renvoie rien
    private function UrlFileFree($strUrl)
    {
        $ret = false;
    
        $data = array('submit'=>'Download');
        $data = http_build_query($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_URL, $strUrl);
    
        $page = curl_exec($curl);
        curl_close($curl);
        
        $ret = $page;
        return $ret;
    }
  
    //verifie si le lien est valide et retourne le nom du fichier si c'est le cas. Renvoie false si ça n'est pas le cas
    private function CheckLink($strUrl)
    {
        $ret = false;
        $data = array('links[]'=>$strUrl);
        $data = http_build_query($data);
    
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_URL, $this->CHECKLINK_URL_REQ);
        $page = curl_exec($curl);
        curl_close($curl);
    
        preg_match($this->FILE_OFFLINE_REGEX, $page, $errormatch);
        if(!isset($errormatch[0]))
        {
            $result = explode(';', $page);
            $ret = $result[1];
        }
        
        return $ret;
    }
  
    /*renvoie si le compte est authentifié, si c'est le cas renvoie GRATUIT ou PREMIUM
    FAIL = error
    premium = serie de chiffres
    free = 0
    */
    private function TypeAccount($Username, $Password)
    {
        $ret = false;
    
        $queryUrl = 'https://1fichier.com/console/account.pl?user='.$Username.'&pass='.md5($Password);
    
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_URL, $queryUrl);
        $page = curl_exec($curl);
        curl_close($curl);
    
        if($page == 'error')
        {
            $ret = LOGIN_FAIL;
      
        }else if($page == '0')
        {
        	$ret = USER_IS_FREE;
      
        }else
        {
            $ret = USER_IS_PREMIUM;
        }
        return $ret; 
    }
  
    //extrait l'identifiant du fichier de l'url entré
    private function GetFILEID($strUrl)
    {
        $ret = false;
        
        /*si l'url est sous le nouveau format elle renvoie son ID
        si elle est sous l'ancien format, renvoie également son ID
        sinon renvoie faux
        */
        preg_match($this->FILEID_REGEX, $strUrl, $FILEIDMatch);
    
        if(!empty($FILEIDMatch[1]))
        {
            $this->FILEID = $FILEIDMatch[1];
            $ret = true;
        }else
        {
            preg_match($this->FILEID_OLD_REGEX, $strUrl, $FILEIDMatch);
            if(!empty($FILEIDMatch[1]))
            {
                $this->FILEID = $FILEIDMatch[1];
                $ret = true;
            }
        }
        return $ret;
    }
  
    /*créé une URL propre qui permettra de l'utiliser correctement
    */
    private function MakeUrl()
    { 
        //créé une url d'origine propre
        $this->ORIGINAL_URL = 'https://1fichier.com/?'.$this->FILEID;
        $this->Url = $this->ORIGINAL_URL.'&lg=en';
    }
}
?>
