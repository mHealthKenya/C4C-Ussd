<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\UssdRequest;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class C4cJitengeUssdController extends Controller
{
    const END_POINT = "http://c4c_api.mhealthkenya.org/api/exposures/covid/new";

    private $sessionOpeningTag = "CON C4C";

    private $sessionClosingTag = "END C4C";

    private function getSession($sessionId)
    {
        return Cache::get($sessionId);
    }

    private function setSession(array $session)
    {
        $sessionDurationMinutes = 15;

        Cache::put($session["sessionId"], $session, $sessionDurationMinutes);
    }

    private function deleteSession($session)
    {
        if (empty($session))
            return $session;

        if (!isset($session['sessionId']))
            return null;
            
        return Cache::pull($session["sessionId"]);    
    }

    protected $client;

    public function __construct()
		{
			$this->client = new Client([
                'verify' => false
            ]);
		}

    public function handleRequest(UssdRequest $request)
		{
			$sessionId = request("sessionId");
			$phoneNumber = request("phoneNumber");
			$input = trim(request("text"));
            $session = $this->getSession($sessionId);
            
			if ($input == "") {
				
				$response = $this->sessionOpeningTag . "\nEnter your phone number";
				
			} else {
				
				$parts = array_filter(explode('*', $input));
				
                $arraySize = count($parts);	
                					
					switch ($arraySize) {
						
                        case 1:   
                                   							
							$isValidNumber = false;
							$phoneNumberObject = null;
							$phoneNumberUtil = PhoneNumberUtil::getInstance();
							if ($phoneNumberUtil->isPossibleNumber($parts[0], "KE")) {
								$phoneNumberObject = $phoneNumberUtil->parse($parts[0], "KE");
								$isValidNumber = $phoneNumberUtil->isValidNumberForRegion($phoneNumberObject, "KE");
                            }                           
							
							if (!$isValidNumber || $phoneNumberObject == null) {
								
								$response = "$this->sessionClosingTag\nYou have entered an invalid phone number. Try again.";
								
								$this->deleteSession($session);
								
							}else{

                                $session['sessionId'] = $sessionId;
								
								$session["phone_number"] = preg_replace('/\s+/', '', trim($phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::INTERNATIONAL)));
                                
                                $this->setSession($session);

                                $response = "$this->sessionOpeningTag\nEnter your password";
                            }                        
														
                            break;
                            
                        case 2: 

                            $password = $parts[1];                                
                                
							$response = $this->client->post('http://c4c_api.mhealthkenya.org/api/auth/login', [
									'form_params' => [
                                        'msisdn' => trim(ltrim($session["phone_number"], "+")),
                                        'password' => $password
									],
									'cookies' => false
									]
                            );                               
								
                            $response = json_decode($response->getBody());    
															
							if (!empty($response) && $response->success === true) {

                                dd($response);
																											
								$session['token'] = $response->access_token;
									
								$session['client_id'] = $response->user->id;
									
                                $this->setSession($session);

                                $response = $this->performEvaluation($session, $parts);

                            } else {
                                $response = "CON C4C\nDownload the C4C App and complete your profile";

                                $this->deleteSession($session);
                            } 
                                
                            break;

						
					}	
				
				
				//return $response;
			}
			
			return response($response, 200)->header("Content-Type", "text/plain");
		}
    

    private function performEvaluation( $session, $parts)
    {

        switch (count($parts)) {

            case 2:
                
                $response = "CON C4C\nHave you had contact with a COVID 19 patient?\n1. Yes\n2. No";

            break;

            case 3:

                $session["contact_person"] = $parts[2] == "1" ? "YES" : "NO";

               $this->setSession($session);

                $response = "CON C4C\nWhen did you get into contact with someone with COVID 19? DDMMYYYY"; 

                break;

            case 4:
                
                if (strlen($parts[4]) != 8) {
								
                    unset($session[4]);
                    
                    $response = "CON C4C\nWhen did you get into contact with someone with COVID 19 DDMMYYYY";
                    
                } else {
                    
                    try {
                        
                        $session['contact_date'] = Carbon::createFromFormat('dmY', $parts[4])->format('Y-m-d');
                        
                        $this->setSession($session);
                        
                        $response = "CON C4C\nDid you receive IPC training?\n1. Yes\n2. No";
                        
                    } catch (Exception $exception) {
                        
                        unset($session[4]);
                        
                        $response = "CON C4C\nWhen did you get into contact with someone with COVID 19?";
                        
                    }
                }

            break;

            case 5:

                if ($parts[5] == "1" || $parts[5] == "2") {

                    $session["ipc_training"] = $parts[4] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = "CON C4C\nDuring interaction with a COVID-19 patient, were you wear personal protective equipment (PPE)?\n1. Yes \n2. No";
             
                } else {

                    $response = "CON C4C\nYou have entered an invalid response. Try again.";

                    $this->deleteSession($session);

                }

            break;

            case 6:

                if ($parts[6] == "1" || $parts[6] == "2") {

                    $session["ppe_worn"] = $parts[6] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    if ($parts[6] == "1")

                        $response = "CON C4C\nDuring interaction with a COVID-19 patient, did you wear personal protective equipment (PPE)?\n1. Single Gloves\n2. N95 mask (or equivalent)\n3. Face shield or goggles/protective glasses\n4. Disposable gown\n5. Waterproof apron\n6. None";
             
                    } else {

                        $response = "CON C4C\nYou have entered an invalid response. Try again.";

                         $this->deleteSession($session);

                    }

            break;

            case 7:

                if ($parts[7] == "1" || $parts[7] == "2") {

                    $session["ppe_worn"] = $parts[7] == "1" ? "YES" : "NO";

                    $this->setSession($session);

                    $response = "CON C4C\nWhich of these symptoms are you experiencing any of these symptoms?\n1. Fever\n2. Cough\n3. Difficulty in breathing\n4. Fatigue\n5. Sneezing\n6. Sore throat\n7. None";

            
                } else {

                $response = "CON C4C\nYou have entered an invalid response. Try again";

                $this->deleteSession($session);
            }

            break;

        }

        return $response;
  }
}
