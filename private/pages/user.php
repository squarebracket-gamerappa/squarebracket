<?php

namespace OpenSB;

global $auth, $domain, $enableFederatedStuff, $activityPubAdapter, $database, $twig;

use SquareBracket\CommentData;
use SquareBracket\CommentLocation;
use SquareBracket\SubmissionData;
use SquareBracket\UnorganizedFunctions;
use SquareBracket\SubmissionQuery;

$submission_query = new SubmissionQuery($database);

$username = $path[2] ?? null;

if (isset($_GET['name'])) UnorganizedFunctions::redirect('/user/' . $_GET['name']);

if ($enableFederatedStuff) {
    // TODO: following and followers
    if (str_contains($_SERVER['HTTP_ACCEPT'], 'application/ld+json') ||
        str_contains($_SERVER['HTTP_ACCEPT'], 'application/activity+json')) {
        require(SB_PRIVATE_PATH . '/pages/activitypub/user.php');
        die();
    } elseif (str_contains($_SERVER['REQUEST_URI'], '/inbox')) {
        require(SB_PRIVATE_PATH . '/pages/activitypub/inbox.php');
        die();
    }
}

function handleFeaturedSubmission($database, $data): false|array
{
    global $auth;

    // handle featured submission
    // if user hasn't specified anything, then use latest submission, if that doesn't exist, do not bother.
    $featured_id = $database->fetch("SELECT video_id FROM videos v WHERE v.id = ?", [$data["featured_submission"]]);

    if ($featured_id == 0 || !$featured_id) {
        $featured_id = $database->fetch(
            "SELECT video_id FROM videos v WHERE v.author = ? ORDER BY v.time DESC", [$data["id"]]);
        if(!isset($featured_id["video_id"])) {
            return false;
        }
        if ($featured_id == 0) {
            return false;
        }
    }

    $submission = new SubmissionData($database, $featured_id["video_id"]);
    $submission_data = $submission->getData();
    $bools = $submission->bitmaskToArray();

    // IF:
    // * The submission is taken down, and/or
    // * The submission no longer exists and/or
    // * The submission's author is not the user whose profile we're looking at and/or
    // * The submission is not available to guests and the user isn't signed in and/or
    // * TODO: The submission is privated...
    // then simply just return false, so we don't show the featured submission.
    if (
        $submission->getTakedown()
        || !$submission_data
        || ($submission_data["author"] != $data["id"])
        || ($bools["block_guests"] && !$auth->isUserLoggedIn())
    )
    {
        return false;
    } else {
        return [
            "title" => $submission_data["title"],
            "id" => $submission_data["video_id"],
            "type" => $submission_data["post_type"],
        ];
    }
}

$isFediverse = false;
$whereRatings = UnorganizedFunctions::whereRatings();

$instance = null;
if (str_contains($username, "@" . $domain) && $enableFederatedStuff) {
    // if the handle matches our domain then don't treat it as an external fediverse account
    $extractedAddress = explode('@', $username);
    $data = $database->fetch("SELECT * FROM users u WHERE u.name = ?", [$extractedAddress[0]]);
} elseif (str_contains($username, "@") && $enableFederatedStuff) {
    // if the handle contains "@" then check if it's in our db
    $isFediverse = true;
    $extractedAddress = explode('@', $username);
    $instance = $extractedAddress[1];
    $data = $database->fetch(
        "SELECT * FROM users u INNER JOIN activitypub_user_urls ON activitypub_user_urls.user_id = u.id WHERE u.name = ?", [$username]);
} else {
    // otherwise it's a normal opensb account
    $data = $database->fetch("SELECT * FROM users u WHERE u.name = ?", [$username]);
}

if (!$data)
{
    // check if this username was used before and was changed out of.
    $old_username_data = $database->fetch("SELECT user FROM user_old_names WHERE old_name = ?", [$username]);

    if ($old_username_data) {
        // if so, redirect to the new profile.
        $new_username = $database->fetch("SELECT name FROM users WHERE id = ?", [$old_username_data['user']])["name"];
        http_response_code(301);
        header("Location: /user/$new_username");
        exit();
    } else if ($isFediverse) {
        // if we know if it's a fediverse account, then try getting its profile and then copying it over to our
        // database. (TODO: handle blacklisted instances)
        if (!$activityPubAdapter->getFediProfileFromWebFinger($username)) {
            UnorganizedFunctions::Notification("This user and/or instance does not exist.", "/");
        }
    } else {
        UnorganizedFunctions::Notification("This user does not exist.", "/");
    }
}

// shit, how will bans work via fediverse?
if ($database->fetch("SELECT * FROM bans WHERE userid = ?", [$data["id"]]))
{
    UnorganizedFunctions::Notification("This user is banned.", "/");
}

$user_submissions = $submission_query->query("v.time desc", 12, "v.author = ?", [$data["id"]]);

$user_journals =
    $database->fetchArray(
        $database->query("SELECT j.* FROM journals j WHERE
                         j.author = ? 
                         ORDER BY j.date 
                         DESC LIMIT 3", [$data["id"]]));

$is_own_profile = ($data["id"] == $auth->getUserID());

if ($is_own_profile || $auth->isUserAdmin()) {
    $old_usernames = $database->fetchArray($database->query("SELECT * FROM user_old_names WHERE user = ?", [$data["id"]]));
} else {
    $old_usernames = [];
}

$comments = new CommentData($database, CommentLocation::Profile, $data["id"]);

$followers = $database->result("SELECT COUNT(user) FROM subscriptions WHERE id = ?", [$data["id"]]);
$followed = UnorganizedFunctions::IsFollowingUser($data["id"]);
$views = $database->result("SELECT SUM(views) FROM videos WHERE author = ?", [$data["id"]]);

$profile_data = [
    "id" => $data["id"],
    "username" => $data["name"],
    "displayname" => $data["title"],
    "about" => ($data['about'] ?? false),
    "joined" => $data["joined"],
    "connected" => $data["lastview"],
    "is_current" => $is_own_profile,
    "featured_submission" => handleFeaturedSubmission($database, $data),
    "submissions" => UnorganizedFunctions::makeSubmissionArray($database, $user_submissions),
    "journals" => UnorganizedFunctions::makeJournalArray($database, $user_journals),
    "comments" => $comments->getComments(),
    "followers" => $followers,
    "following" => $followed,
    "is_fedi" => $isFediverse,
    "views" => $views,
    "old_usernames" => $old_usernames,
];

if ($isFediverse) {
    $profile_data["instance"] = $instance;

    if (isset($data["profile_picture"])) {
        $profile_data["fedi_pfp"] = $data["profile_picture"];
    } else {
        $profile_data["fedi_pfp"] = "/assets/profiledef.png";
    }

    if (isset($data["banner_picture"])) {
        $profile_data["fedi_banner"] = $data["banner_picture"];
    } else {
        $profile_data["fedi_banner"] = "/assets/biscuit_banner.png";
    }
}

echo $twig->render('profile.twig', [
    'data' => $profile_data,
]);