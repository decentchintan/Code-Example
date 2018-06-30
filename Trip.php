<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class Trip extends CI_Controller {

    function __Construct() {

        parent::__construct();
        if (!$this->session->userdata('user_id')) {
            redirect("Checklogin");
        }
        $this->load->library('form_validation');

        $this->load->model('Trip_model');
    }

    function index() {
        $this->load->view('select_process');
    }
    
    function add_trip(){
        
        $this->load->library('email');
        $this->load->model('Myalert_model');
        $post_data = $this->input->post();
        $user_id = $this->session->userdata('user_id');
        $user_email = $this->session->userdata('user_email');
        if($post_data){
            $this->Trip_model->save_trip($post_data);
            $latitude = $post_data['destination_latitude'];
            $longitude = $post_data['destination_langitude'];
            $query = "SELECT DISTINCT *, ( 3959 * acos( cos( radians($latitude) ) * cos( radians( `destination_latitude` ) ) 
              * cos( radians( `destination_longitude` ) - radians($longitude) ) + sin( radians($latitude) ) 
              * sin( radians( `destination_latitude` ) ) ) ) AS distance FROM trips WHERE user_id!=$user_id HAVING distance < 100 ORDER BY distance LIMIT 0 , 20";
            $query = $this->db->query($query);
            $result = $query->result();
            // var_dump($post_data);exit;
            if (!empty($result)) {
                $message_activate = site_url().'index.php/Myalert';
                $data['activate'] = $message_activate;
            }
            else {
                $data['activate'] = NULL;
            }
            
            
            
            // Check Condition for Match, if Yes then Send Mail to Traveler
            $grab_ids = array();
            if(isset($post_data['can_buy'])){
            
                $can_buy = 1;
            }
            else{
                $can_buy = 0;
            }
            
            if(isset($post_data['can_deliver_at_other'])){
                $can_deliver_at_other = 1;
            }
            else{
                $can_deliver_at_other = 0;
            }
            
            $free_space_cabin = 0;
            if(isset($post_data['free_space_cabin'])){
                $free_space_cabin = $post_data['free_space_cabin'];
            }
            
            $free_space_checked = 0;
            if(isset($post_data['free_space_checked'])){
                $free_space_checked = $post_data['free_space_checked'];
            }
            
            $total_weight = $free_space_cabin + $free_space_checked;
            
            $related_grab_result = $this->Myalert_model->get_match_grab($post_data['destination_latitude'],$post_data['destination_langitude'],$post_data['departure_date'],$post_data['source_country'],$can_buy,$can_deliver_at_other,$total_weight);
            
            foreach($related_grab_result as $related_grab_results){
                $data_mail['grab'] = $related_grab_results;
                $graber_deliver_status = $this->Myalert_model->check_grab_deliver_status($related_grab_results->id);
                if(count($graber_deliver_status) < 1){
                    if(!in_array($related_grab_results->id,$grab_ids)){ 
                        //echo "test";die;
                        $message_activate = site_url().'/index.php/Myalert';
                        $data_mail['activate'] = $message_activate;
                        $this->email->from('support@voyey.com', 'La #TeamVoyey');
                        $this->email->subject('[Voyey.com] - Votre voyage a été enregistré avec succès !');
                        $data_mail['name']=$this->session->userdata('user_fname');
                        
                        $body=$this->load->view('emails/grab_notif.php',$data_mail, TRUE); //chargement du template
                        
                        $this->email->message($body);
                        $this->email->to($user_email);
                        try{
                            $this->email->send();
                        }catch(Exception $e){
                            $this->session->set_flashdata('dispMessage',$e->getMessage());
                            echo $e->getMessage();exit;
                        }
                    }
                }
            
                 $grab_ids[] = $related_grab_results->id;
            }
            // Send Mail to traveler End
            
            
            $data['trip'] = $post_data;
            $this->email->from('support@voyey.com', 'La #TeamVoyey');
            $this->email->subject('[Voyey.com] - Votre voyage a été enregistré avec succès !');
            $data['name']=$this->session->userdata('user_fname');
            $body=$this->load->view('emails/trip.php',$data, TRUE); //chargement du template
            
            $this->email->message($body);
            $this->email->to($user_email);
            try{
                $this->email->send();
            }catch(Exception $e){
                $this->session->set_flashdata('dispMessage',$e->getMessage());
                echo $e->getMessage();exit;
            }
            
            $this->session->set_flashdata('dispMessage','Voyage publié avec succes');
            redirect('Trip/my_trips');
        }
        $this->load->view('add_trip');
    }
    
    function my_trips(){
        
        $this->load->model('Profile_model');
        $user_id = $this->session->userdata('user_id');
        $personal_info = $this->Profile_model->get_user_data($user_id);
        $data['personal_info'] = $personal_info;
        
        $user_future_trips = $this->Trip_model->get_users_future_trips();
        $data['user_sms_notification'] = $user_future_trips;
        $this->load->view('my_trips',$data);
    }
    
    function edit_trip($trip_id){
        
        $trip_id = decode($trip_id);
        $user_id = $this->session->userdata('user_id');
        $trip_data = $this->Trip_model->get_trip($trip_id);
        if($trip_data->user_id != $user_id)
        {
            redirect('Profile');
        }
        $data['trip_data'] = $trip_data;
        $this->load->view('edit_trip',$data);
    }
    
    function update_trip(){
        
        $user_email = $this->session->userdata('user_email');
        $this->load->library('email');
        $this->load->model('Myalert_model');
        $post_data = $this->input->post();
        $trip_data = $this->Trip_model->update_trip($post_data);
        $this->session->set_flashdata('dispMessage','Voyage mis à jour avec succès');
        
        // Check Condition for Match, if Yes then Send Mail to Traveler
            $grab_ids = array();
            if(isset($post_data['can_buy'])){
            
                $can_buy = 1;
            }
            else{
                $can_buy = 0;
            }
            
            if(isset($post_data['can_deliver_at_other'])){
                $can_deliver_at_other = 1;
            }
            else{
                $can_deliver_at_other = 0;
            }
            
            $free_space_cabin = 0;
            if(isset($post_data['free_space_cabin'])){
                $free_space_cabin = $post_data['free_space_cabin'];
            }
            
            $free_space_checked = 0;
            if(isset($post_data['free_space_checked'])){
                $free_space_checked = $post_data['free_space_checked'];
            }
            
            $total_weight = $free_space_cabin + $free_space_checked;
            
            $related_grab_result = $this->Myalert_model->get_match_grab($post_data['destination_latitude'],$post_data['destination_langitude'],$post_data['departure_date'],$post_data['source_country'],$can_buy,$can_deliver_at_other,$total_weight);
            
            foreach($related_grab_result as $related_grab_results){
                $data_mail['grab'] = $related_grab_results;
                $graber_deliver_status = $this->Myalert_model->check_grab_deliver_status($related_grab_results->id);
                
                if(count($graber_deliver_status) < 1){
                    
                    if(!in_array($related_grab_results->id,$grab_ids)){ 
                                   
                        $message_activate = site_url().'/index.php/Myalert';
                        $data_mail['activate'] = $message_activate;
                        $this->email->from('support@voyey.com', 'La #TeamVoyey');
                        $this->email->subject('[Voyey.com] - Votre voyage a été enregistré avec succès !');
                        $data_mail['name']=$this->session->userdata('user_fname');
                        
                        $body=$this->load->view('emails/grab_notif.php',$data_mail, TRUE); //chargement du template
                        
                        $this->email->message($body);
                        $this->email->to($user_email);
                        
                        try{
                            $this->email->send();
                        }catch(Exception $e){
                            
                            $this->session->set_flashdata('dispMessage',$e->getMessage());
                            echo $e->getMessage();exit;
                        }
                    }
                }
            
                 $grab_ids[] = $related_grab_results->id;
            }
            // Send Mail to traveler End
        
        $this->email->from('support@voyey.com', 'La #TeamVoyey');
        $this->email->to($user_email);
        $objet = "[Voyey.com] - Votre voyage a été modifié avec succès";
        $in_iso8859encoded = iconv("UTF-8", "ISO-8859-1", $objet);
        $out_iso8859 = htmlentities($in_iso8859encoded, ENT_COMPAT, "ISO-8859-1");
        // $objets = htmlentities($objet, ENT_COMPAT);
        $subjects = html_entity_decode($objets);
        $this->email->subject("[Voyey.com] - Votre voyage a été modifié avec succès");
        $data['name']=$this->session->userdata('user_fname');
        $data['trip'] = $this->Trip_model->get_trip($post_data['trip_id']);
        $body=$this->load->view('emails/modif_trip.php',$data, TRUE); //chargement du template
        
        $this->email->message($body); 
        try{
            $this->email->send();
        }catch(Exception $e){
            $this->session->set_flashdata('dispMessage',$e->getMessage());
        }
        redirect('Trip/my_trips');
    }
    
    function trip_delete($trip_id = 0)
    {
        $trip_id = decode($trip_id);
        $this->load->library('email');
        $user_id = $this->session->userdata('user_id');
        $trip = $this->Trip_model->get_trip($trip_id);
        if($trip->user_id != $user_id)
        {
            redirect('Profile');
        }
        
        $grab_delete = $this->Trip_model->trip_delete($trip_id);
        $this->session->set_flashdata('dispMessage','Voyage supprimé avec succès');
        $this->email->from('support@voyey.com', 'La #TeamVoyey');
        $this->email->to($_SESSION['user_email']);
        $this->email->subject('[Voyey.com] - Votre voyage a été supprimé avec succès !');
        $data['name']=$this->session->userdata('user_fname');
        $data['trip'] = $trip;
        $body=$this->load->view('emails/delete_trip.php',$data, TRUE); //chargement du template
        
        $this->email->message($body); 
        try{
            $this->email->send();
        }catch(Exception $e){
            $this->session->set_flashdata('dispMessage',$e->getMessage());
        }
        redirect('Trip/my_trips');
    }
    
}