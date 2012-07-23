<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Question behaviour for the old adaptive mode.
 *
 * @package    qbehaviour
 * @subpackage adaptive
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

//require the base class
require_once($CFG->dirroot.'/question/behaviour/adaptive/behaviour.php');

/**
 * Question behaviour for adaptive mode.
 *
 * This is the old version of interactive mode.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveweighted extends qbehaviour_adaptive
{
    /**
     * Perform the actual grading, as part of the submit step.
     * 
     * @param question_attempt_pending_step $pendingstep
     * @return boolean
     */
    public function process_submit(question_attempt_pending_step $pendingstep) 
    {
    	//first, call the save event processing code, and get the default question status
        $status = $this->process_save($pendingstep);

        //get the response information for the new step
        $response = $pendingstep->get_qt_data();
        
        //if the user's response isn't gradeable (e.g. a non-numeric answer for a numeric quesiton type)
        //set the state to invalid, and don't penalize the user
        if (!$this->question->is_gradable_response($response)) 
        {
        	//mark the pending response as invalid 
            $pendingstep->set_state(question_state::$invalid);
            
            //as long as the current attempt state isn't invalid, keep the user's response without grading it  
            if ($this->qa->get_state() != question_state::$invalid) 
                $status = question_attempt::KEEP;
            
            //return the grading status
            return $status;
        }

        //get some information about the previous attempt(s):
        
        //the previous step object
        $prevstep = $this->qa->get_last_step_with_behaviour_var('_try');
        
        //the previous response
        $prevresponse = $prevstep->get_qt_data();
        
        //the number of prior tries
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        
        //the running total of penalties
        $prevsumpenalty = $this->qa->get_last_behaviour_var('_sumpenalty', 0);
        
        //and the highest score attained previously
        $prevbest = $pendingstep->get_fraction();

        //if we don't have a prior best score, assume the student has not yet earned any points
        if (is_null($prevbest)) 
            $prevbest = 0;

        //if we've recieved the same response twice (possibly due to multiple clicks of the 'check' button)
        //discard the latter attempt
        if ($this->question->is_same_response($response, $prevresponse)) 
            return question_attempt::DISCARD;

        //grade the current response
        list($fraction, $state) = $this->question->grade_response($response);

        //calculate the user's adjusted grade, based on their new grade, and the number of tries taken
        $adjusted_grade = $this->adjusted_fraction($fraction, $prevsumpenalty);
        
        //and set their end-result score equal to their best score so far
        $pendingstep->set_fraction(max($prevbest, $adjusted_grade));
        
        //if the previous step was a complete response, then mark this response as complete, as well
        if ($prevstep->get_state() == question_state::$complete) 
            $pendingstep->set_state(question_state::$complete);
        
        //if the user achieved the correct answer, mark this attempt as complete
        else if ($state == question_state::$gradedright)
            $pendingstep->set_state(question_state::$complete);
        
        //otherwise, indicate that the question can be continued
        else 
            $pendingstep->set_state(question_state::$todo);

        
       //calculate the penalty for the given attempt
       $lastpenalty = (1 - $fraction) * $this->question->penalty;
            
       //and store it as the most recent penalty
       $pendingstep->set_behaviour_var('_lastpenalty', $lastpenalty);
            
       //increase the penalty counter for subsequent attempts (if possible ?)
       //the penalty is equal to the default penalty multiplied by the percent incorrect
       $sumpenalty = $prevsumpenalty + $lastpenalty;
            
        //increment the attempt counter
        $pendingstep->set_behaviour_var('_try', $prevtries + 1);
        
        //indicate the student's grade before penalties
        $pendingstep->set_behaviour_var('_rawfraction', $fraction);
        
        //calculate a new sumpenalty
        $pendingstep->set_behaviour_var('_sumpenalty', $sumpenalty);
        
        //and set the displayed response summary
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        //return KEEP, indicating that this response should not be discarded
        return question_attempt::KEEP;
    }

    /**
     * Process the finish event, which occurs when quizzes are submitted.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) 
    {
    	//if the quiz is already finished, discard the attempt
        if ($this->qa->get_state()->is_finished()) 
            return question_attempt::DISCARD;

        //get the amount of expended tries, and the previous best score
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $this->qa->get_fraction();
        
        //get the running sum of penalties
        $prevsumpenalty = $this->qa->get_last_behaviour_var('_sumpenalty', 0);

        //if the student has no score, assume they've earned no marks
        if (is_null($prevbest)) 
            $prevbest = 0;

        //get the most recent step, and response
        $laststep = $this->qa->get_last_step();
        $response = $laststep->get_qt_data();
        
        //if the student response isn't gradeable, the student must have given up (or never figured out how to enter in answer)
        if (!$this->question->is_gradable_response($response)) 
        {
        	//adjust the state accordingly, and don't give the student any marks for their invalid attempt
            $state = question_state::$gaveup;
            $fraction = 0;
        } 
        //otherwise, grade the student response
        else 
        {

        	//if the final step is a graded attempt, then this is a regrade:
        	//we're going to ignore that final grading, and thus won't count its attempt as expended 
            if ($laststep->has_behaviour_var('_try')) 
                $prevtries -= 1;

            //grade the given question
            list($fraction, $state) = $this->question->grade_response($response);

            //and mark one more attempt as expended
            $pendingstep->set_behaviour_var('_try', $prevtries + 1);
            
            //store the raw score for the question
            $pendingstep->set_behaviour_var('_rawfraction', $fraction);
            
            //calculate the penalty for the given attempt
            $lastpenalty = (1 - $fraction) * $this->question->penalty;
            
            //and store it as the most recent penalty
            $pendingstep->set_behaviour_var('_lastpenalty', $lastpenalty);
            
            //increase the penalty counter for subsequent attempts (if possible ?)
            //the penalty is equal to the default penalty multiplied by the percent incorrect
            $sumpenalty = $prevsumpenalty + $lastpenalty;
            
            //calculate a new sumpenalty
            $pendingstep->set_behaviour_var('_sumpenalty', $sumpenalty);
            
            //and store the response's summary
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }

        //set the question's state to either finalized, or abandoned, according to the above
        $pendingstep->set_state($state);
        
        //calculate the student's grade, after penalties
        $adjusted_fraction = $this->adjusted_fraction($fraction, $prevsumpenalty);
        
        //and store the student's score
        $pendingstep->set_fraction(max($prevbest, $adjusted_fraction));
        
        //no matter what, always keep a non-duplicate final submission
        return question_attempt::KEEP;
    }
    
    /**
     * The core grading function, which determines the way in which a raw grade is adjusted to
     * apply penalties.
     */
    protected function adjusted_fraction($fraction, $sumpenalty)
    {
    	return max($fraction - $sumpenalty, 0);
    }

}
