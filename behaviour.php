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
 * Modified Adaptive Weighted behavior for queued questions (like 
 *
 * This is the old version of interactive mode.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveweighted_queued extends qbehaviour_adaptive
{
    const IS_ARCHETYPAL = false;

    /**
     * Handles any user (student and otherwise) interactions with a question attempt
     * that uses this behavior.
     *
     * This function delegates each action to the appropriate handler subroutine.
     *
     * @param question_attempt_pending_step An object describing the data submitted by
     *    the user during interaction with the function. 
     */
    public function process_action(question_attempt_pending_step $pendingstep) {

        //If the pending step is an external update (which occurs when judging is
        //completed after the attempt is closed), handle it using the process update
        //function.
        if($pendingstep->has_behaviour_var('update')) {
            return $this->process_update($pendingstep);
        } 
        //Otherwise, delegate to the parent action handler, which in turn delegates
        //to the appropriate method below.
        else {
            return parent::process_action($pendingstep);
        }
    }

    /**
     * Perform the actual grading, as part of the submit step.
     * 
     * @param question_attempt_pending_step $pendingstep
     * @return boolean
     */
    public function process_submit(question_attempt_pending_step $pendingstep) 
    {
  	    //First, call the save event processing code, and get the default question status.
        $status = $this->process_save($pendingstep);

        //Get the response information for the new step.
        $response = $pendingstep->get_qt_data();
        
        //If the user's response isn't complete,
        //set the state to invalid, and don't penalize the user.
        if (!$this->question->is_complete_response($response)) 
        {
            $pendingstep->set_state(question_state::$invalid);
            
            //As long as the current attempt state isn't invalid, keep the user's response without grading it.
            if ($this->qa->get_state() != question_state::$invalid) 
                $status = question_attempt::KEEP;
            
            return $status;
        }

        //Add the response to the graing queue, and mark it as "requiring grading".
        $this->queue_grading($pendingstep, $response);

        //return KEEP, indicating that this response should not be discarded
        return question_attempt::KEEP;
    }

    /**
     * Requests that the given response be graded.
     */
    protected function queue_grading($step, $response) {
    
        //If the question does not have a queue method, raise a coding exception.
        if(!method_exists($this->question, 'queue_grading')) {
            throw new coding_exception('Questions which use queued behaviors must support queue_for_grading. '.get_class($this->question) . ' does not.');
        }

        //Ask the question to grade the provided response.
        $task_id = $this->question->queue_grading($response, $this->qa->get_usage_id(), $this->qa->get_slot());
        $step->set_behaviour_var('_task_id', $task_id);
        $step->set_state(question_state::$needsgrading);

        //Adjust the question's summary.
        $step->set_new_response_summary($this->question->summarise_response($response));

    }


    public function process_save(question_attempt_pending_step $pendingstep) {

        $status = parent::process_save($pendingstep);

        //Get the task ID of the most recent queued task.
        $task_id = $this->qa->get_last_behaviour_var('_task_id', -1);

        //If a task is outstanding (>=0) and has been graded...
        if($task_id >= 0 && $this->question->queued_grading_is_complete($task_id)) {

            //... apply it.
            $this->apply_queued_grade($pendingstep);

            //... and keep the given step.
            return question_attempt::KEEP;
        } 
        //Otherwise, perform a normal save, and return.
        else {
            return $status; 
        }
    }

    

    /**
     * Apply a queued grading operation.
     *
     * @param question_attempt_pending_step $pendingstep a partially initialised step
     *      containing all the information about the action that is being peformed.
     * @return bool either {@link question_attempt::KEEP}
     */
    public function apply_queued_grade(question_attempt_pending_step $pendingstep) {

        //Get the result of the newly-created task.
        $task_id = $this->qa->get_last_behaviour_var('_task_id', -1);
        $result = $this->question->get_queued_grading_result($task_id);

        //Import the result of the newly-created task into the pending step.
        $this->import_queued_grading_result($pendingstep, $result);

        //Mark the given grade as "handled".
        $pendingstep->set_behaviour_var('_task_id', -1);

        //get the response information for the new step
        $response = $pendingstep->get_qt_data();

        //if the user's response isn't gradeable (e.g. a non-numeric answer for a numeric quesiton type)
        //set the state to invalid, and don't penalize the user
        if (!$this->question->post_process_response_is_gradable($response)) 
        {
        	//mark the pending response as invalid 
            $pendingstep->set_state(question_state::$todo);
            return;
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
        if (is_null($prevbest)) {
            $prevbest = 0;
        }

        //grade the current response
        list($fraction, $state) = $this->question->grade_response($response);

        //calculate the user's adjusted grade, based on their new grade, and the number of tries taken
        $adjusted_grade = $this->adjusted_fraction($fraction, $prevsumpenalty);
        
        //and set their end-result score equal to their best score so far
        $pendingstep->set_fraction(max($prevbest, $adjusted_grade));
        
        //if the previous step was a complete response, then mark this response as complete, as well
        //if ($prevstep->get_state() == question_state::$complete) 
        //    $pendingstep->set_state(question_state::$complete);
        
        //if the user achieved the correct answer, mark this attempt as complete
        if ($state == question_state::$gradedright)
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
     * Internal function which imports an array of QT variables (such as the result of a queued grade) 
     * into a pending attempt step.
     *
     * @param question_attempt_pending_step $pendingstep The step to be modified.
     * @param array $result The array of QT variables to import. Each will be automatically prefixed with a '_'--
     *    do not prefix them yourself!
     */
    protected function import_queued_grading_result(question_attempt_pending_step $pendingstep, array $result) {

        //Import each of the grading results as QT variables.
        foreach($result as $name => $value) {
            $pendingstep->set_qt_var('_'.$name, $value); 
        }
    
    }

    /**
     * Process the finish event, which occurs when quizzes are submitted.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) 
    {
    	//if the quiz is already finished, discard the attempt
        if ($this->qa->get_state()->is_finished()) { 
            return question_attempt::DISCARD;
        }

        //get the amount of expended tries, and the previous best score
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $this->qa->get_fraction();
        
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
            if ($laststep->has_behaviour_var('_try')) {
                $prevtries -= 1;
            }

            $this->queue_grading($pendingstep, $response);
        }
        
        //no matter what, always keep a non-duplicate final submission
        return question_attempt::KEEP;
    }

    /**
     * @return int The ID of this question's active task, in the format supplied by the question.
     * Returns null if no active task exists.
     */
    public function get_active_task_id() {

        //Get the ID of the task, or -1 if it does not exist. 
        // '-1' is the internal code for "no active task".
        $task_id =  $this->qa->get_last_behaviour_var('_task_id', -1 );
        return ($task_id < 0) ? null : $task_id;

    }

    /**
     * Returns the step which should be used to generate specific and general feedback.
     * 
     * @return question_attempt_step The step whose values should be used to determine feedback,
     * or an empty step if no applicable step exists.
     */
    public function get_feedback_step() {
        return $this->qa->get_last_step_with_behaviour_var('_task_id');
    }
    
    /**
     * The core grading function, which determines the way in which a raw grade is adjusted to
     * apply penalties.
     */
    protected function adjusted_fraction($fraction, $sumpenalty)
    {
    	return max($fraction - $sumpenalty, 0);
    }

    /**
     *  
     */
    public function get_state_string($show_correctness) {

        //If this question is "in the queue" waiting to be graded,
        //list it as "waiting to be graded" instead of complete...
        if($this->qa->get_state() == question_state::$needsgrading) {
            return get_string('needsgrading', 'qbehaviour_adaptiveweighted_queued');
        }
        else {
            return parent::get_state_string($show_correctness);
        }   

    }

}
