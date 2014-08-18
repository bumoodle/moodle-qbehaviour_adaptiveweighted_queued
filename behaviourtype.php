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
 * Question behaviour type for adaptive behaviour.
 *
 * @package    qbehaviour_adaptiveweighted_queued
 * @copyright  2014 Binghamton University, Kyle J. Temkin <ktemkin@binghamton.edu>
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/question/behaviour/adaptiveweighted/behaviourtype.php');


/**
 * Question behaviour type information for adaptive behaviour.
 *
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptiveweighted_queued_type extends qbehaviour_adaptiveweighted_type {

    public function is_archetypal() {
        //This is used by some question types, but not directly by the user.
        return false;
    }
}
