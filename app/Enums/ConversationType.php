<?php

namespace App\Enums;

enum ConversationType: string
{
    use HasValues;

    case ClientDoctor = 'client_doctor';
    case StudentTeacher = 'student_teacher';
    case Group = 'group';
    case Direct = 'direct';
}
