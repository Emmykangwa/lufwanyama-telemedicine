<?php
/**
 * includes/lang.php
 *
 * The interface is English. t('key') returns the label for a key, so the
 * wording for the whole system lives in one place and stays consistent.
 */

declare(strict_types=1);

$TXT = [
    'app_name'        => 'Lufwanyama Telemedicine',
    'tagline'         => 'Request your health centre appointment and consult a doctor before you travel.',
    'home'            => 'Home',
    'login'           => 'Login',
    'register'        => 'Register',
    'logout'          => 'Logout',
    'welcome'         => 'Welcome',
    'dashboard'       => 'Dashboard',

    'full_name'       => 'Full name',
    'phone'           => 'Phone number',
    'email'           => 'Email (optional)',
    'password'        => 'Password',
    'login_id'        => 'Phone number or email',
    'dob'             => 'Date of birth',
    'sex'             => 'Sex',
    'female'          => 'Female',
    'male'            => 'Male',
    'village'         => 'Village',
    'role'            => 'Role',

    'patient'         => 'Patient',
    'patients'        => 'Patients',
    'patient_number'  => 'Patient number',
    'facility'        => 'Facility',
    'facilities'      => 'Facilities',
    'centre'          => 'Health centre',
    'doctor'          => 'Doctor',
    'any_doctor'      => 'Any doctor',
    'department'      => 'Department',
    'date'            => 'Date',
    'reason'          => 'Reason',
    'notes'           => 'Clinical notes',
    'response'        => 'Doctor response',
    'status'          => 'Status',

    'my_appointments' => 'My appointments',
    'book'            => 'Book appointment',
    'new_appointment' => 'New appointment',
    'add_patient'     => 'Add patient',
    'incoming'        => 'Appointment requests',
    'my_schedule'     => 'My schedule',
    'respond'         => 'Respond',
    'users'           => 'Staff accounts',
    'audit'           => 'Audit log',
    'reports'         => 'Reports',

    'submit'          => 'Submit',
    'save'            => 'Save',
    'apply'           => 'Apply',
    'print'           => 'Print',
    'search'          => 'Search',
    'select'          => '-- select --',
    'from'            => 'From',
    'to'              => 'To',
    'action'          => 'Action',
    'new_date'        => 'New date',
    'withdraw'        => 'Withdraw',
    'no_records'      => 'No records yet.',

    'pending'         => 'Pending',
    'confirmed'       => 'Confirmed',
    'rescheduled'     => 'Rescheduled',
    'declined'        => 'Declined',
    'completed'       => 'Completed',

    'offline'         => 'No connection. Your entry is saved on this phone and will be sent when the network returns.',
    'session_expired' => 'Your session ended for safety. Please log in again.',
    'please_login'    => 'Please log in first.',
    'travel_warning'  => 'Only travel to the health centre once your status is Confirmed or Rescheduled.',
];

/** t('login') returns the interface label for a key. */
function t(string $key): string
{
    global $TXT;
    return $TXT[$key] ?? $key;
}
