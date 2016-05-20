<?php
/**
 * ILIAS REST Plugin for the ILIAS LMS
 *
 * Authors: D.Schaefer, T.Hufschmidt <(schaefer|hufschmidt)@hrz.uni-marburg.de>
 * Since 2014
 */
namespace RESTController\extensions\courses_v1;

// This allows us to use shortcuts instead of full quantifier
use \RESTController\libs\RESTAuth as RESTAuth;
use \RESTController\libs as Libs;
use \RESTController\core\auth as Auth;
use \RESTController\extensions\users_v1 as Users;
use \RESTController\extensions\courses_v1 as Courses;

$app->group('/v1', function () use ($app) {


    /**
     * Retrieves a list of all courses of the authenticated user and meta-information about them (no content).
     */
   $app->get('/courses', RESTAuth::checkAccess(RESTAuth::PERMISSION), function () use ($app) {
        $accessToken = $app->request->getToken();
        $user_id = $accessToken->getUserId();
        try {
        $crs_model = new CoursesModel();
        $data1 =  $crs_model->getAllCourses($user_id);

        $result = array(
            'courses' => $data1
        );
        $app->success($result);
        } catch (Libs\Exceptions\ReadFailed $e) {
            $app->halt(500, $e->getFormatedMessage());
        }
    });

    /**
     * Retrieves the content and a description of a course specified by ref_id.
     */
    $app->get('/courses/:ref_id', RESTAuth::checkAccess(RESTAuth::PERMISSION), function ($ref_id) use ($app) {
        $app->log->debug('in course get ref_id= '.$ref_id);
        try {
            $crs_model = new CoursesModel();
            $data1 = $crs_model->getCourseContent($ref_id);
            $data2 = $crs_model->getCourseInfo($ref_id);
            $include_tutors_and_admints = true;
            $data3 = $crs_model->getCourseMembers($ref_id, $include_tutors_and_admints);

            $result = array(
                'contents' => $data1, // course contents
                'info' => $data2,     // course info
                'members' => $data3   // course members
            );
            $app->success($result);
        } catch (Libs\Exceptions\ReadFailed $e) {
            $app->halt(500, $e->getFormatedMessage());
        }
    });


    $app->post('/courses', RESTAuth::checkAccess(RESTAuth::PERMISSION), function() use ($app) {
        try {
            $request = $app->request();
            $ref_id = $request->params('ref_id', null, true);
            $title = $request->params('title', null, true);
            $description = $request->params('description', '');

            Libs\RESTilias::loadIlUser();
            Libs\RESTilias::initAccessHandling();
            if(!$GLOBALS['ilAccess']->checkAccess("create_crs", "", $ref_id))
                $app->halt(401, "Insufficient access rights");

            $crs_model = new CoursesModel();
            $new_ref_id =  $crs_model->createNewCourse($ref_id, $title, $description);

            $result = array('refId' => $new_ref_id);
            $app->success($result);
        }
        catch (Libs\Exceptions\MissingParameter $e) {
            $app->halt(400, $e->getFormatedMessage(), $e::ID);
        }
    });

    /**
     * Deletes the course specified by ref_id.
     */
    $app->delete('/courses/:ref_id', RESTAuth::checkAccess(RESTAuth::PERMISSION), function ($ref_id) use ($app) {
        $request = $app->request();

        $accessToken = $app->request->getToken();
        $user_id = $accessToken->getUserId();
        global $ilUser;
        Libs\RESTilias::loadIlUser();
        $ilUser->setId((int)$user_id);
        $ilUser->read();
        Libs\RESTilias::initAccessHandling();

        global $rbacsystem;

        if ($rbacsystem->checkAccess('delete',$ref_id)) {
            $result = array();
            $crs_model = new CoursesModel();
            $soap_result = $crs_model->deleteCourse($ref_id);

            $app->success($soap_result);
        } else {
            $app->success(array("msg"=>"No Permission."));
        }

    });


    /**
     * Enroll a user to a course.
     * Expects a "mode" parameter ("by_login"/"by_id") that determines the
     * lookup method for the user.
     * If "mode" is "by_login", the "login" parameter is used for the lookup.
     * If no user is found, a new LDAP user is created with attributes from
     * the "data" array.
     * If "mode" is "by_id", the parameter "usr_id" is used for the lookup.
     * The user is then enrolled in the course with "crs_ref_id".
     */
    $app->post('/courses/enroll', RESTAuth::checkAccess(RESTAuth::ADMIN), function() use ($app) {
        $request = $app->request();
        $mode = $request->params("mode");

        if($mode == "by_login") {
            $login = $request->params("login");
            $user_id = Libs\RESTilias::getUserName($login);
            if(empty($user_id)){
                $data = $request->params("data");
                $userData = array_merge(array(
                    "login" => "{$login}",
                    "auth_mode" => "ldap",
                ), $data);
                $um = new Users\UsersModel();
                $user_id = $um->addUser($userData);
            }
        }
        else if ($mode == "by_id")
            $user_id = $request->params("usr_id");
        else
            $app->halt(400, "Unsupported or missing mode: '$mode'. Use eiter 'by_login' or 'by_id'");

        $crs_ref_id = $request->params("crs_ref_id");
        try {
            $crsreg_model = new CoursesRegistrationModel();
            $crsreg_model->joinCourse($user_id, $crs_ref_id);
        } catch (Libs\Exceptions\CreateFailed $e) {
            // TODO: Replace message with const-class-variable and error-code with unique string
            $app->halt(400, "Error: Subscribing user ".$user_id." to course with ref_id = ".$crs_ref_id." failed. Exception:".$e->getMessage());
        }

        if($mode == "by_login")
            $app->success(array("msg"=>"Enrolled user $login to course with id $crs_ref_id"));
        else
            $app->success(array("msg"=>"Enrolled user with id $user_id to course with id $crs_ref_id"));
    });

    /**
     * Assigns the authenticated user to a course specified by the GET parameter ref_id.
     */
    $app->get('/courses/join/:ref_id', RESTAuth::checkAccess(RESTAuth::PERMISSION), function ($ref_id) use ($app) {
        $accessToken = $app->request->getToken();
        $authorizedUserId = $accessToken->getUserId();

        $request = $app->request();
        try {
            //$ref_id = $request->params("ref_id");
            $crsreg_model = new CoursesRegistrationModel();
            $crsreg_model->joinCourse($authorizedUserId, $ref_id);

            $result = array(
                'msg' => "User ".$authorizedUserId." subscribed to course with ref_id = " . $ref_id . " successfully.",
            );
            $app->success($result);
        } catch (Courses\SubscriptionFailed $e) {
            $app->halt(400, "Error: Subscribing user ".$authorizedUserId." to course with ref_id = ".$ref_id." failed. Exception:".$e->getMessage(), -15);
        }
    });

    /**
     * Removes the authenticated user from a course speicifed by the GET parameter "ref_id".
     */
    $app->get('/courses/leave/:ref_id', RESTAuth::checkAccess(RESTAuth::PERMISSION), function ($ref_id) use ($app) {
        $accessToken = $app->request->getToken();
        $authorizedUserId = $accessToken->getUserId();

        try {
            $crsreg_model = new CoursesRegistrationModel();
            $crsreg_model->leaveCourse($authorizedUserId, $ref_id);
            $app->success(array("msg"=>"User ".$authorizedUserId." has left course with ref_id = " . $ref_id . "."));

        } catch (Courses\CancelationFailed $e) {
            $app->halt(400, 'Error: Could not perform action for user '.$authorizedUserId.". ".$e->getMessage(), -15);
        }
    });

    /**
     * Download representation of learning module identified by its ref_id
     */
    $app->get('/courses/download/lm/:ref_id', RESTAuth::checkAccess(RESTAuth::PERMISSION), function ($ref_id) use ($app) {

    });

});
