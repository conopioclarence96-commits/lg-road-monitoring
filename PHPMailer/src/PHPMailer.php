<?php
/**
 * PHPMailer - PHP email creation and transport class
 * PHP Version 5.2.34
 * @package PHPMailer
 * @link https://github.com/PHPMailer/PHPMailer/
 * @author Marcus Bointon (coolbru@phpmailer.com)
 * @copyright 2012-2017 Marcus Bointon
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

// Minimal PHPMailer implementation for Gmail SMTP
class PHPMailer {
    public $To = '';
    public $From = 'conopioclarence96@gmail.com';
    public $FromName = 'LGU Portal';
    public $Subject = '';
    public $Body = '';
    public $Host = 'smtp.gmail.com';
    public $Port = 587;
    public $SMTPAuth = true;
    public $Username = 'conopioclarence96@gmail.com';
    public $Password = 'dlcd mkxi qcec dgri';
    public $SMTPSecure = 'tls';
    public $CharSet = 'UTF-8';
    
    public function send() {
        $to = $this->To;
        $subject = $this->Subject;
        $message = $this->Body;
        
        // Create email headers
        $headers = "From: {$this->FromName} <{$this->From}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset={$this->CharSet}\r\n";
        
        // Send using PHP mail function
        return mail($to, $subject, $message, $headers);
    }
}
