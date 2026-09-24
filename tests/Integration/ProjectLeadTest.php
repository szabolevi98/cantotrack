<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Auth;
use CantoTrack\Model\AuditRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\AuditLog;

final class ProjectLeadTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Auth::actAs(null);
    }

    public function testALeadRunsTheirProjectAndNoOther(): void
    {
        $lead = $this->person('Anna Kovács');
        $mine = $this->project('CT');
        $theirs = $this->project('WEB');
        $projects = new ProjectRepository($this->db);
        $projects->addMember($mine, $lead);
        $users = new UserRepository($this->db);

        Auth::actAs((array) $users->find($lead));
        self::assertFalse(Auth::leads($mine), 'a member is not a lead');

        $projects->setMemberRole($mine, $lead, 'lead');
        self::assertTrue(Auth::leads($mine));
        self::assertFalse(Auth::leads($theirs));
    }

    public function testAnAdministratorLeadsEverythingAndAGuestNothing(): void
    {
        $admin = $this->person('Tamás Farkas', true, 'admin');
        $guest = $this->person('Kata Guest', true, 'guest');
        $project = $this->project();
        $projects = new ProjectRepository($this->db);
        $projects->addMember($project, $guest);
        $projects->setMemberRole($project, $guest, 'lead');
        $users = new UserRepository($this->db);

        Auth::actAs((array) $users->find($admin));
        self::assertTrue(Auth::leads($project));

        Auth::actAs((array) $users->find($guest));
        self::assertFalse(Auth::leads($project), 'a guest leads nothing, whatever the row says');
    }

    public function testTheRecordSaysWhoDidWhatAndCanBeNarrowed(): void
    {
        $admin = $this->person('Tamás Farkas', true, 'admin');
        $other = $this->person('Anna Kovács');
        $users = new UserRepository($this->db);

        Auth::actAs((array) $users->find($admin));
        AuditLog::record('project_deleted', 'project', 7, 'OLD — Old project');
        AuditLog::record('signin_failed', 'user', null, 'someone@example.test');
        Auth::actAs((array) $users->find($other));
        AuditLog::record('password_changed', 'user', $other, 'anna@example.test');

        $audit = new AuditRepository($this->db);
        self::assertSame(3, $audit->count([]));
        self::assertSame(['password_changed', 'signin_failed'], array_column($audit->page(['group' => 'access']), 'action'));
        self::assertSame(['project_deleted'], array_column($audit->page(['user_id' => $admin, 'q' => 'Old']), 'action'));
        self::assertSame('Tamás Farkas', $audit->page(['group' => 'projects'])[0]['user_name']);
    }

    public function testWhatChangedIsSaidInAFewWords(): void
    {
        self::assertSame(
            'role: member → admin, active: yes → no',
            AuditLog::changes(['name' => 'Anna', 'role' => 'member', 'active' => true], ['name' => 'Anna', 'role' => 'admin', 'active' => false])
        );
    }
}
