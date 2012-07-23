Weighted Adaptive Behavior for Moodle 2.1+

A modified version of the Adaptive question behavior which weights its penalties according to the percent wrong.

Authored by Kyle Temkin, working for Binghamton University <http://www.binghamton.edu>

To install Moodle 2.1+ using git, execute the following commands in the root of your Moodle install:

    git clone git://github.com/ktemkin/moodle-qbehavior_adaptive.git question/behaviour/adaptiveweighted
    echo '/question/behaviour/adaptiveweighted' >> .git/info/exclude

Or, extract the following zip in your_moodle_root/question/type/:

    https://github.com/ktemkin/moodle-qbehavior_adaptiveweighted/zipball/master
