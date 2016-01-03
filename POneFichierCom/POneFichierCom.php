<?php 

/*Auteur : warkx
  Version originale Developpé le : 23/11/2013
  Version : 2.0
  Développé le : 03/01/2016
  Description : Support du compte premium uniquement*/
  
class SynoFileHosting
{
    private $Url; 
    private $HostInfo;
    private $Username;
    private $Password;
    private $FILEID;
    private $ORIGINAL_URL;
   
    private $CHECKLINK_URL_REQ = 'https://1fichier.com/check_links.pl';
    
    private $FILEID_REGEX = '`https?:\/\/1fichier\.com\/\?([a-zA-Z0-9]+)\/?`i';
    private $FILEID_OLD_REGEX = '`https?:\/\/([a-z0-9A-Z]+)\.1fichier\.com\/?`i';
    private $FILE_OFFLINE_REGEX = '`BAD LINK|NOT FOUND`i';
    private $PREMIUM_REAL_URL_REGEX = '`https?:\/\/[a-z0-9]+-[a-z0-9]+\.1fichier\.com\/[a-z0-9]+`i';
  
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
        
        //Recupere l'id, s'il nest pas bon, renvoie NON PRIS EN CHARGE
        $GetFILEIDRet = $this->GetFILEID($this->Url);
        if ($GetFILEIDRet == false)
        {
            $ret[DOWNLOAD_ERROR] = ERR_NOT_SUPPORT_TYPE;
        }else
        {
           //créé une url d'origine propre
           
            $this->ORIGINAL_URL = 'https://1fichier.com/?'.$this->FILEID;
            $this->Url = $this->ORIGINAL_URL.'&lg=en&auth=1&e=1';
            
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
                $ret = $this->DownloadPremium();
                
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
        $ret = USER_IS_PREMIUM;
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
                $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
            }else
            {
                $DownloadInfo[DOWNLOAD_ERROR] = ERR_REQUIRED_PREMIUM;
            }
        }
        return $DownloadInfo;
    }
    
    //Telecharge la page en se connectant
    private function DownloadPageWithAuth()
    {
        $ret = false;
        
        $option = array(CURL_OPTION_FOLLOWLOCATION => true);
        $curl = GenerateCurl($this->Url,$option);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($curl, CURLOPT_USERPWD, $this->Username.':'.$this->Password);
        $ret = curl_exec($curl);
        curl_close($curl);
        
        return $ret;
    }
  
    //verifie si le lien est valide et retourne le nom du fichier si c'est le cas. Renvoie false si ça n'est pas le cas
    private function CheckLink($strUrl)
    {
       $ret = false;
       $data = array('links[]'=>$strUrl);
       
       $option = array(CURL_OPTION_POSTDATA => $data);
       $curl = GenerateCurl($this->CHECKLINK_URL_REQ,$option);
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
}
?>
