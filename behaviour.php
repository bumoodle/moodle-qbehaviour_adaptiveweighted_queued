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
require_once($CFG->dirroot.'/question/behaviour/adaptiveweighted/behaviour.php');

/**
 * Modified Adaptive Weighted behavior for queued questions (like 
 *
 * This is the old version of interactive mode.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveweighted_queued extends qbehaviour_adaptiveweighted
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

        //Get the most recently queued step.
        list($last_queued_step) = $this->get_queued_steps(1, true);

        //If the response hasn't changed since the last time this was submitted, discard it.
        if($this->question->is_same_response($response, $last_queued_step->get_qt_data())) {
            $pendingstep->set_state(question_state::$todo);
            return question_attempt::DISCARD;
        }

        //If the user's response isn't complete,
        //set the state to invalid, and don't penalize the user.
        if (!$this->question->is_complete_response($response, $this->qa->get_database_id())) 
        {
            //Set the current step to invalid...
            $pendingstep->set_state(question_state::$todo);

            //If this isn't a second invalid state in a row, keep it.
            if ($this->qa->get_state() != question_state::$todo) {
                $status = question_attempt::KEEP;
            }
            
            return $status;
        }

        //Add the response to the graing queue, and mark it as "requiring grading".
        $this->queue_grading($pendingstep, $response, false);

        //return KEEP, indicating that this response should not be discarded
        return question_attempt::KEEP;
    }

    /**
     * Requests that the given response be graded.
     */
    protected function queue_grading($step, $response, $finished=false) {
    
        //If the question does not have a queue method, raise a coding exception.
        if(!method_exists($this->question, 'queue_grading')) {
            throw new coding_exception('Questions which use queued behaviors must support queue_for_grading. '.get_class($this->question) . ' does not.');
        }

        //Mark the response as "complete"-- which means that we have everything that we 
        //need from the student to begin grading. Grading will continue in the background. 
        $target_state = $finished ? question_state::$needsgrading : question_state::$complete;
        $step->set_state($target_state);

        //Ask the question to grade the provided response.
        $task_id = $this->question->queue_grading($response, $this->qa->get_usage_id(), $this->qa->get_slot());
        $step->set_behaviour_var('_task_id', $task_id);

        //Mark this step as a "queue" submission step.
        $step->set_behaviour_var('_queued', 1);

        //Adjust the question's summary.
        $step->set_new_response_summary($this->question->summarise_response($response));

    }

    public function process_save(question_attempt_pending_step $pendingstep) {

        //Ensure that an attemptid is passed to the given question.
        $pendingstep->set_qt_var('_attemptid', $this->qa->get_database_id());

        //Perform the main save process.
        $status = parent::process_save($pendingstep);

        //If we are waiting for the system to grade a queued response...
        if($this->qa->get_state() == question_state::$complete) {

            //Get the task ID of the most recent queued task.
            $task_id = $this->qa->get_last_behaviour_var('_task_id', -1);

            //If a task is outstanding (>=0) and has been graded
            if($task_id >= 0 && $this->question->queued_grading_is_complete($task_id)) {

                //... apply it, and keep the question state.
                $this->apply_queued_grade($pendingstep);

                //... and keep the given step.
                return question_attempt::KEEP;
            } 
            //Otherwise, remain in the "complete" state.
            else {
                return question_attempt::DISCARD;
            }

        } else {
            return $status;
        }
    }

    public function process_update(question_attempt_pending_step $pendingstep) {


        //Apply queued grading.
        $this->apply_queued_grade($pendingstep); 

        //If we're keeping the response, ensure we mark it as complete.
        $pendingstep->set_state(question_state::graded_state_for_fraction($pendingstep->get_fraction()));

        //Always keep updates.
        return question_attempt::KEEP;

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

        //And copy the results from the _last_ try.

        //Import the result of the newly-created task into the pending step.
        $this->import_queued_grading_result($pendingstep, $result);

        //Mark the given grade as "handled".
        $pendingstep->set_behaviour_var('_task_id', -1);

        //Mark this step as a grading step.
        $pendingstep->set_behaviour_var('_graded', 1);

        //get the response information for the new step
        $response = $pendingstep->get_qt_data();

        //if the user's response isn't gradeable (e.g. a non-numeric answer for a numeric quesiton type)
        //set the state to invalid, and don't penalize the user
        if (!$this->question->post_process_response_is_gradable($response)) 
        {
        	//mark the pending response as invalid 
            $pendingstep->set_state(question_state::$todo);
            return question_attempt::KEEP;
        }

        //get some information about the previous attempt(s):

        //Get the last _graded_ step
        $prevstep = $this->get_feedback_step();
        
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
            $pendingstep->set_state(question_state::$gradedright);
        
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

        //If this question is already in a finished state, skip finishing it.
        if($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD; 
        }

        //Ensure the question is in its most up-to-date state.
        $status = $this->process_save($pendingstep);

        //If a grading task is outstanding, leave this question in the "pending grading" state.
        if($this->get_active_task_id()) {
            $pendingstep->set_state(question_state::$needsgrading);
            return question_attempt::KEEP;
        }

        //get the amount of expended tries, and the previous best score
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        
        //get the most recent step, and response
        $laststep = $this->qa->get_last_step();
        $response = $laststep->get_qt_data();

        //if the student response isn't gradeable, the student must have given up (or never figured out how to enter in answer)
        if (!$this->question->is_gradable_response($response, $this->qa->get_database_id())) 
        {
        	//adjust the state accordingly, and don't give the student any marks for their invalid attempt
            $pendingstep->set_state(question_state::$gaveup);
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

            //Add this to the grading queue.
            $this->queue_grading($pendingstep, $response, true);
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
        return $this->qa->get_last_step_with_behaviour_var('_graded');
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
        if($this->qa->get_state() == question_state::$complete) {
            return get_string('needsgrading', 'qbehaviour_adaptiveweighted_queued');
        }
        else {
            return parent::get_state_string($show_correctness);
        }   

    }

    /**
     * Returns all "enqueue" (true submission) steps.
     * If a limit N is specified, it returns  N of the most recent enqueue steps.
     * If pad is set, and less than N steps are available, remaining steps are padded with question_attempt_null steps.
     *
     * @param int $limit The maximum number of steps that should be returned.
     * @param array An array of question_attempt_steps, in chronological order.
     */ 
    protected function get_queued_steps($limit = PHP_INT_MAX, $pad=false) {

        $steps = array();

        //Iterate backwards through each of this question's steps.
        foreach($this->qa->get_reverse_step_iterator() as $step) {

            //If this is a queued step, return it.
            if($step->has_behaviour_var('_queued')) {
                $steps[] = $step; 
            }

            //Stop when/if we get to the specified amount of steps.
            if(count($steps) >= $limit) {
                break; 
            }
        }

        //If the pad option is set, and we're less than the limit, pad until the array is at that point.
        if($pad) {
            $steps = array_pad($steps, $limit, new question_attempt_step_read_only());
        }

        //Return the array _reversed_, as to be in chronological order.
        return array_reverse($steps);
    }

}
