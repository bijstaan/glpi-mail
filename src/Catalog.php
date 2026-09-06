<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * Every notification GLPI ships, described as blocks.
 *
 * One entry per stock template, keyed by the `name` GLPI seeds it with in
 * `install/empty_data.php`. Names rather than ids: both come from the same
 * seed, but an id is a number that means nothing when you are reading this
 * file, and a template somebody deleted and recreated keeps its name.
 *
 * ### The tags are checked, not guessed
 *
 * Every `##tag##` below was read out of the corresponding `NotificationTarget`
 * subclass on GLPI 11.0.8 — from where it assigns `$this->data[...]`, not from
 * the tag list it registers for the help panel. Those two disagree in places,
 * and where they do the assignment is the one that decides what a mail
 * actually contains:
 *
 *  - **Infocom** registers `##FOREACHitems##` but fills `$this->data['infocoms']`,
 *    so `##FOREACHinfocoms##` is the loop that works. GLPI's own stock template
 *    has this right and its own tag list has it wrong.
 *  - **Certificates and domains** are only ever filled *inside* their loops —
 *    `$this->data['certificates']`, `$this->data['domains']` — apart from the
 *    entity name. GLPI's stock templates for both use the flat tags with no
 *    loop around them, which is why a stock certificate-expiry mail arrives
 *    with the entity filled in and every other field blank. These use the loops.
 *  - **Reservation** stock uses `##reservation.tech##`, which does not exist;
 *    the assignment is `##reservation.item.tech##`.
 *
 * ### On language
 *
 * `##lang.*##` is substituted with a label translated into the *recipient's*
 * language at send time, so it is used for every field label here. The handful
 * of words that are ours rather than GLPI's — button verbs, the eyebrow over
 * the title — have no such tag and are written in English. A template row
 * carries `language = ''`, meaning "any", and there is exactly one of them; the
 * alternative is a row per installed language and thirty templates times thirty
 * languages of generated markup for a five-word difference.
 */
final class Catalog
{
    /**
     * Everything that has a description — GLPI's own, plus every plugin's.
     *
     * Contributed entries are collected by {@see Registry} from the
     * `glpimail_letters` hook and cannot displace one of GLPI's; see that
     * class. A template with no entry here is not unknown so much as
     * undescribed, and {@see Templates} wraps it instead.
     *
     * @return array<string,array{itemtype:string,summary:string,plugin:string,blocks:array<int,array<mixed>>}>
     */
    public static function all(): array
    {
        return self::core() + Registry::letters();
    }

    /**
     * GLPI's own thirty-two, and nothing else.
     *
     * Separate from {@see self::all()} because {@see Registry} needs to know
     * what a contributor may not redefine, and because the pure-PHP test can
     * exercise this half with no `$PLUGIN_HOOKS` in sight.
     *
     * @return array<string,array{itemtype:string,summary:string,plugin:string,blocks:array<int,array<mixed>>}>
     */
    public static function core(): array
    {
        $n = Settings::loopLimit();

        return self::stamp([
            // ============================================================ ITIL

            'Tickets' => [
                'itemtype' => 'Ticket',
                'summary'  => 'The main ticket notification — new, updated, solved, closed, followups and tasks.',
                'blocks'   => self::itil('ticket', [
                    'timeline' => true,
                    'approval' => true,
                    'items'    => true,
                ]),
            ],

            'Tickets (Simple)' => [
                'itemtype' => 'Ticket',
                'summary'  => 'The short form, for requesters who want the headline and a link.',
                'blocks'   => [
                    ['eyebrow', '##ticket.action##'],
                    ['title', '##ticket.title##'],
                    self::statusPills('ticket'),
                    ['button', 'Open the ticket', '##ticket.url##'],
                    ['meta', [
                        ['##lang.ticket.status##', '##ticket.status##'],
                        ['##lang.ticket.authors##', '##ticket.authors##'],
                        ['##lang.ticket.assigntousers##', '##ticket.assigntousers##', 'ticket.assigntousers'],
                        ['##lang.ticket.creationdate##', '##ticket.creationdate##'],
                    ]],
                    ['panel', '##lang.ticket.description##', '##ticket.content##'],
                ],
            ],

            'Alert Tickets not closed' => [
                'itemtype' => 'Ticket',
                'summary'  => 'The digest of tickets left open past their threshold.',
                'blocks'   => [
                    ['eyebrow', '##ticket.action##'],
                    ['title', 'Tickets still open'],
                    ['lede', 'These tickets have been open longer than this entity allows. Each one needs an update or a close.'],
                    ['loop', 'tickets', null, [
                        ['divider'],
                        ['link', '##ticket.title##', '##ticket.url##'],
                        ['meta', [
                            ['##lang.ticket.status##', '##ticket.status##'],
                            ['##lang.ticket.priority##', '##ticket.priority##'],
                            ['##lang.ticket.authors##', '##ticket.authors##'],
                            ['##lang.ticket.attribution##', '##ticket.assigntousers## ##ticket.assigntogroups##'],
                            ['##lang.ticket.creationdate##', '##ticket.creationdate##'],
                        ]],
                    ]],
                ],
            ],

            'Tickets Approval' => [
                'itemtype' => 'Ticket',
                'summary'  => 'A validation request, and the answer to one.',
                'blocks'   => [
                    ['loop', 'validations', null, [
                        // storestatus 2 is "waiting": the request going out.
                        // Anything else is the answer coming back. GLPI's own
                        // template splits on the same value.
                        ['ifeq', 'validation.storestatus', '2', [
                            ['eyebrow', 'Approval requested'],
                            ['title', '##validation.submission.title##'],
                            ['lede', 'Someone has asked for your approval on this ticket. It will not move until you answer.'],
                            ['button', 'Review and answer', '##ticket.urlvalidation##'],
                            ['panel', '##lang.validation.commentsubmission##', '##validation.commentsubmission##'],
                        ], [
                            ['eyebrow', 'Approval answered'],
                            ['title', '##validation.answer.title##'],
                            ['pills', [
                                ['##validation.status##', 'info'],
                            ]],
                            ['button', 'Open the ticket', '##ticket.urlvalidation##'],
                            ['panel', '##lang.validation.commentvalidation##', '##validation.commentvalidation##'],
                        ]],
                        ['meta', [
                            ['##lang.ticket.title##', '##ticket.title##'],
                            ['##lang.validation.author##', '##validation.author##'],
                            ['##lang.validation.validator##', '##validation.validator##'],
                            ['##lang.validation.submissiondate##', '##validation.submissiondate##'],
                            ['##lang.validation.validationdate##', '##validation.validationdate##', 'validation.validationdate'],
                        ]],
                    ]],
                ],
            ],

            'Ticket Satisfaction' => [
                'itemtype' => 'Ticket',
                'summary'  => 'The survey invitation sent after a ticket closes.',
                'blocks'   => self::satisfaction('ticket'),
            ],

            'Automatic reminder' => [
                'itemtype' => 'Ticket',
                'summary'  => 'The nudge sent while a ticket is waiting on the requester.',
                'blocks'   => [
                    ['eyebrow', '##ticket.action##'],
                    ['title', '##ticket.title##'],
                    ['lede', 'This ticket is waiting on you. Without a reply it will be closed automatically.'],
                    ['button', 'Reply to the ticket', '##ticket.url##'],
                    ['meta', [
                        ['##lang.ticket.reminder.deadline##', '##ticket.reminder.deadline##'],
                        ['##lang.ticket.reminder.bumpcounter##', '##ticket.reminder.bumpcounter##'],
                        ['##lang.ticket.reminder.bumpremaining##', '##ticket.reminder.bumpremaining##'],
                        ['##lang.ticket.reminder.bumptotal##', '##ticket.reminder.bumptotal##'],
                    ]],
                    ['panel', '##lang.ticket.reminder.text##', '##ticket.reminder.text##'],
                ],
            ],

            'Problems' => [
                'itemtype' => 'Problem',
                'summary'  => 'Problem records — raised, updated, solved and closed.',
                'blocks'   => self::itil('problem', [
                    'timeline' => true,
                    'analysis' => true,
                ]),
            ],

            'Changes' => [
                'itemtype' => 'Change',
                'summary'  => 'Change records, with the plans an approver needs to read.',
                'blocks'   => self::itil('change', [
                    'timeline' => true,
                    'plans'    => true,
                ]),
            ],

            'Change Satisfaction' => [
                'itemtype' => 'Change',
                'summary'  => 'The survey invitation sent after a change closes.',
                'blocks'   => self::satisfaction('change'),
            ],

            // ===================================================== reservations

            'Reservations' => [
                'itemtype' => 'Reservation',
                'summary'  => 'One reservation, booked, changed or cancelled.',
                'blocks'   => [
                    ['eyebrow', '##reservation.action##'],
                    ['title', '##reservation.itemtype## — ##reservation.item.name##'],
                    ['button', 'Open the reservation', '##reservation.url##'],
                    ['meta', [
                        ['##lang.reservation.user##', '##reservation.user##'],
                        ['##lang.reservation.begin##', '##reservation.begin##'],
                        ['##lang.reservation.end##', '##reservation.end##'],
                        ['##lang.reservation.item.entity##', '##reservation.item.entity##'],
                        // The assignment is `item.tech`. GLPI's own stock
                        // template asks for `##reservation.tech##`, which is
                        // not a tag, and prints it literally.
                        ['##lang.reservation.item.tech##', '##reservation.item.tech##', 'reservation.item.tech'],
                    ]],
                    ['if', 'reservation.comment', [
                        ['panel', '##lang.reservation.comment##', '##reservation.comment##'],
                    ]],
                ],
            ],

            'Alert Reservation' => [
                'itemtype' => 'Reservation',
                'summary'  => 'The digest of reservations about to expire.',
                'blocks'   => [
                    ['eyebrow', '##reservation.action##'],
                    ['title', 'Reservations ending soon'],
                    ['meta', [
                        ['##lang.reservation.entity##', '##reservation.entity##'],
                    ]],
                    ['loop', 'reservations', null, [
                        ['divider'],
                        ['link', '##reservation.itemtype## — ##reservation.item##', '##reservation.url##'],
                    ]],
                ],
            ],

            // ======================================================= stock alerts

            'Cartridges' => [
                'itemtype' => 'CartridgeItem',
                'summary'  => 'Cartridge stock below its threshold.',
                'blocks'   => self::stockAlert('cartridge', 'cartridges', 'Cartridges to order'),
            ],

            'Consumables' => [
                'itemtype' => 'ConsumableItem',
                'summary'  => 'Consumable stock below its threshold.',
                'blocks'   => self::stockAlert('consumable', 'consumables', 'Consumables to order'),
            ],

            // ====================================================== expiry alerts

            'Infocoms' => [
                'itemtype' => 'Infocom',
                'summary'  => 'Warranties and financial information about to expire.',
                'blocks'   => [
                    ['eyebrow', '##infocom.action##'],
                    ['title', 'Warranties expiring'],
                    ['meta', [
                        ['##lang.infocom.entity##', '##infocom.entity##'],
                    ]],
                    // `infocoms`, not `items`. See the class comment.
                    ['loop', 'infocoms', null, [
                        ['divider'],
                        ['link', '##infocom.itemtype## — ##infocom.item##', '##infocom.url##'],
                        ['meta', [
                            ['##lang.infocom.expirationdate##', '##infocom.expirationdate##'],
                        ]],
                    ]],
                ],
            ],

            'Licenses' => [
                'itemtype' => 'SoftwareLicense',
                'summary'  => 'Software licences about to expire.',
                'blocks'   => [
                    ['eyebrow', '##license.action##'],
                    ['title', 'Licences expiring'],
                    ['meta', [
                        ['##lang.license.entity##', '##license.entity##'],
                    ]],
                    ['loop', 'licenses', null, [
                        ['divider'],
                        ['link', '##license.item##', '##license.url##'],
                        ['meta', [
                            ['##lang.license.serial##', '##license.serial##'],
                            ['##lang.license.expirationdate##', '##license.expirationdate##'],
                        ]],
                    ]],
                ],
            ],

            'Contracts' => [
                'itemtype' => 'Contract',
                'summary'  => 'Contracts reaching their end, their notice period or a renewal date.',
                'blocks'   => [
                    ['eyebrow', '##contract.action##'],
                    ['title', 'Contracts needing attention'],
                    ['meta', [
                        ['##lang.contract.entity##', '##contract.entity##'],
                    ]],
                    ['loop', 'contracts', null, [
                        ['divider'],
                        ['link', '##contract.name##', '##contract.url##'],
                        ['meta', [
                            ['##lang.contract.number##', '##contract.number##'],
                            ['##lang.contract.type##', '##contract.type##', 'contract.type'],
                            ['##lang.contract.time##', '##contract.time##'],
                            ['##lang.contract.account##', '##contract.account##', 'contract.account'],
                        ]],
                    ]],
                ],
            ],

            'Certificates' => [
                'itemtype' => 'Certificate',
                'summary'  => 'Certificates about to expire.',
                'blocks'   => [
                    ['title', 'Certificates expiring'],
                    ['lede', 'A certificate that expires unnoticed takes a service down with it.'],
                    ['meta', [
                        ['##lang.certificate.entity##', '##certificate.entity##'],
                    ]],
                    // The loop is not optional here: outside it, every
                    // certificate tag but the entity is unset. See the class
                    // comment.
                    ['loop', 'certificates', null, [
                        ['divider'],
                        ['link', '##certificate.name##', '##certificate.url##'],
                        ['meta', [
                            ['##lang.certificate.serial##', '##certificate.serial##'],
                            ['##lang.certificate.expirationdate##', '##certificate.expirationdate##'],
                        ]],
                    ]],
                ],
            ],

            'Alert domains' => [
                'itemtype' => 'Domain',
                'summary'  => 'Domain names about to expire.',
                'blocks'   => [
                    ['title', 'Domains expiring'],
                    ['lede', 'A lapsed domain takes mail and the website with it, and is not always recoverable.'],
                    ['loop', 'domains', null, [
                        ['divider'],
                        ['meta', [
                            ['##lang.domain.name##', '##domain.name##'],
                            ['##lang.domain.dateexpiration##', '##domain.dateexpiration##'],
                        ]],
                    ]],
                ],
            ],

            // ============================================================ people

            'Password Forget' => [
                'itemtype' => 'User',
                'summary'  => 'The password reset link.',
                'blocks'   => [
                    ['eyebrow', '##user.action##'],
                    ['title', 'Reset your password'],
                    ['lede', '##user.realname## ##user.firstname##'],
                    ['note', '##lang.passwordforget.information##'],
                    ['button', 'Choose a new password', '##user.passwordforgeturl##'],
                    ['note', 'If the button does not work, copy this address into your browser:'],
                    ['link', '##user.passwordforgeturl##', '##user.passwordforgeturl##'],
                ],
            ],

            'Password Initialization' => [
                'itemtype' => 'User',
                'summary'  => 'The first-password link for a new account.',
                'blocks'   => [
                    ['eyebrow', '##user.action##'],
                    ['title', 'Set your password'],
                    ['lede', '##user.realname## ##user.firstname##'],
                    ['note', '##lang.passwordinit.information##'],
                    ['button', 'Set your password', '##user.passwordiniturl##'],
                    ['note', 'If the button does not work, copy this address into your browser:'],
                    ['link', '##user.passwordiniturl##', '##user.passwordiniturl##'],
                ],
            ],

            'Password expires alert' => [
                'itemtype' => 'User',
                'summary'  => 'The warning before a password expires, and the notice after.',
                'blocks'   => [
                    ['eyebrow', '##user.action##'],
                    ['title', 'Your password'],
                    ['lede', '##user.realname## ##user.firstname##'],
                    ['ifeq', 'user.password.has_expired', '1', [
                        ['pills', [['Expired', 'bad']]],
                        ['note', '##lang.password.has_expired.information##'],
                    ], [
                        ['pills', [['Expiring soon', 'warn']]],
                        ['note', '##lang.password.expires_soon.information##'],
                    ]],
                    ['button', 'Change your password', '##user.password.update.url##'],
                    ['meta', [
                        ['##lang.user.password.expiration.date##', '##user.password.expiration.date##'],
                        ['##lang.user.account.lock.date##', '##user.account.lock.date##', 'user.account.lock.date'],
                    ]],
                ],
            ],

            // ====================================================== housekeeping

            'Item not unique' => [
                'itemtype' => 'FieldUnicity',
                'summary'  => 'A uniqueness rule that something has just broken.',
                'blocks'   => [
                    ['eyebrow', '##unicity.action##'],
                    ['title', 'A duplicate value was refused'],
                    ['pills', [['Duplicate', 'warn']]],
                    ['meta', [
                        ['##lang.unicity.itemtype##', '##unicity.itemtype##'],
                        ['##lang.unicity.entity##', '##unicity.entity##'],
                        ['##lang.unicity.action_user##', '##unicity.action_user##'],
                        ['##lang.unicity.action_type##', '##unicity.action_type##'],
                        ['##lang.unicity.date##', '##unicity.date##'],
                    ]],
                    ['panel', '##lang.unicity.message##', '##unicity.message##'],
                ],
            ],

            'CronTask' => [
                'itemtype' => 'CronTask',
                'summary'  => 'Automatic actions that have stopped running.',
                'blocks'   => [
                    ['eyebrow', '##crontask.action##'],
                    ['title', 'Automatic actions need attention'],
                    ['pills', [['Stalled', 'warn']]],
                    ['note', '##lang.crontask.warning##'],
                    ['loop', 'crontasks', null, [
                        ['divider'],
                        ['link', '##crontask.name##', '##crontask.url##'],
                        ['note', '##crontask.description##'],
                    ]],
                ],
            ],

            'MySQL Synchronization' => [
                'itemtype' => 'DBConnection',
                'summary'  => 'The replica has fallen behind the primary.',
                'blocks'   => [
                    ['eyebrow', '##lang.dbconnection.title##'],
                    ['title', 'The database replica is behind'],
                    ['pills', [['Replication lag', 'bad']]],
                    ['meta', [
                        ['##lang.dbconnection.delay##', '##dbconnection.delay##'],
                    ]],
                    ['note', 'Reads served from the replica may be stale until it catches up.'],
                ],
            ],

            'Receiver errors' => [
                'itemtype' => 'MailCollector',
                'summary'  => 'A mail receiver that can no longer collect.',
                'blocks'   => [
                    ['eyebrow', '##mailcollector.action##'],
                    ['title', 'A mail receiver is failing'],
                    ['pills', [['Collection failing', 'bad']]],
                    ['lede', 'Mail sent to this address is not becoming tickets while this lasts.'],
                    ['loop', 'mailcollectors', null, [
                        ['divider'],
                        ['link', '##mailcollector.name##', '##mailcollector.url##'],
                        ['meta', [
                            ['##lang.mailcollector.errors##', '##mailcollector.errors##'],
                        ]],
                    ]],
                ],
            ],

            'Planning recall' => [
                'itemtype' => 'PlanningRecall',
                'summary'  => 'The reminder before something in the planning starts.',
                'blocks'   => [
                    ['eyebrow', '##recall.action##'],
                    ['title', '##recall.item.name##'],
                    ['button', 'Open it', '##recall.item.url##'],
                    ['meta', [
                        ['##lang.recall.planning.begin##', '##recall.planning.begin##'],
                        ['##lang.recall.planning.end##', '##recall.planning.end##'],
                        ['##lang.recall.planning.state##', '##recall.planning.state##'],
                        ['##lang.recall.item.user##', '##recall.item.user##', 'recall.item.user'],
                    ]],
                    ['panel', '', '##recall.item.content##'],
                ],
            ],

            'Unlock Item request' => [
                'itemtype' => 'ObjectLock',
                'summary'  => 'Somebody is asking for a record you left open.',
                'blocks'   => [
                    ['eyebrow', '##objectlock.action##'],
                    ['title', '##objectlock.type## ###objectlock.id## — ##objectlock.name##'],
                    ['lede', '##objectlock.requester.firstname## ##objectlock.requester.lastname## would like to edit this record, and you still have it open.'],
                    ['button', 'Open it and unlock', '##objectlock.url##'],
                    ['meta', [
                        ['##lang.objectlock.date_mod##', '##objectlock.date_mod##'],
                        ['##lang.objectlock.lockedby.firstname##', '##objectlock.lockedby.firstname## ##objectlock.lockedby.lastname##'],
                    ]],
                ],
            ],

            'Saved searches alerts' => [
                'itemtype' => 'SavedSearch_Alert',
                'summary'  => 'A saved search whose result count crossed its threshold.',
                'blocks'   => [
                    ['eyebrow', '##savedsearch.action##'],
                    ['title', '##savedsearch.name##'],
                    ['pills', [['##savedsearch.count## results', 'info']]],
                    ['lede', '##savedsearch.message##'],
                    ['button', 'Run the search', '##savedsearch.url##'],
                    ['meta', [
                        ['##lang.savedsearch.type##', '##savedsearch.type##'],
                    ]],
                ],
            ],

            'Plugin updates' => [
                'itemtype' => 'Glpi\\Marketplace\\Controller',
                'summary'  => 'Plugin updates waiting in the marketplace.',
                'blocks'   => [
                    ['title', '##lang.plugins_updates_available##'],
                    ['button', 'Open the marketplace', '##marketplace.url##'],
                    ['loop', 'plugins', null, [
                        ['meta', [
                            ['##plugin.name##', '##plugin.old_version## → ##plugin.version##'],
                        ]],
                    ]],
                ],
            ],

            'Knowledge base item' => [
                'itemtype' => 'KnowbaseItem',
                'summary'  => 'An article published or shared with you.',
                'blocks'   => [
                    ['eyebrow', '##knowbaseitem.action##'],
                    ['title', '##knowbaseitem.subject##'],
                    ['button', 'Read the article', '##knowbaseitem.url##'],
                    ['meta', [
                        ['##lang.knowbaseitem.categories##', '##knowbaseitem.categories##'],
                        ['##lang.knowbaseitem.begin_date##', '##knowbaseitem.begin_date##', 'knowbaseitem.begin_date'],
                        ['##lang.knowbaseitem.end_date##', '##knowbaseitem.end_date##', 'knowbaseitem.end_date'],
                    ]],
                    ['panel', '', '##knowbaseitem.content##'],
                    ['if', 'knowbaseitem.numberofdocuments', [
                        ['section', '##lang.knowbaseitem.numberofdocuments##'],
                        ['loop', 'documents', $n, [
                            ['link', '##document.name## (##document.filename##)', '##document.downloadurl##'],
                        ]],
                    ]],
                ],
            ],

            // ========================================================== projects

            'Projects' => [
                'itemtype' => 'Project',
                'summary'  => 'A project created, updated or reaching a milestone.',
                'blocks'   => [
                    ['eyebrow', '##project.action##'],
                    ['title', '##project.name##'],
                    ['pills', [
                        ['##project.state##', 'info'],
                        ['##project.percent##', 'neutral'],
                    ]],
                    ['button', 'Open the project', '##project.url##'],
                    ['meta', [
                        ['##lang.project.code##', '##project.code##', 'project.code'],
                        ['##lang.project.manager##', '##project.manager##', 'project.manager'],
                        ['##lang.project.managergroup##', '##project.managergroup##', 'project.managergroup'],
                        ['##lang.project.planstartdate##', '##project.planstartdate##'],
                        ['##lang.project.planenddate##', '##project.planenddate##'],
                        ['##lang.project.priority##', '##project.priority##'],
                        ['##lang.project.numberoftasks##', '##project.numberoftasks##'],
                    ]],
                    ['panel', '##lang.project.description##', '##project.description##'],
                    ['if', 'project.numberoftasks', [
                        ['section', '##lang.project.tasks##'],
                        ['loop', 'tasks', $n, [
                            ['meta', [
                                ['##task.name##', '##task.state## — ##task.percent##'],
                            ]],
                        ]],
                    ]],
                ],
            ],

            'Project Tasks' => [
                'itemtype' => 'ProjectTask',
                'summary'  => 'A project task created, updated or completed.',
                'blocks'   => [
                    ['eyebrow', '##projecttask.action##'],
                    ['title', '##projecttask.name##'],
                    ['pills', [
                        ['##projecttask.state##', 'info'],
                        ['##projecttask.percent##', 'neutral'],
                    ]],
                    ['button', 'Open the task', '##projecttask.url##'],
                    ['meta', [
                        ['##lang.projecttask.project##', '##projecttask.project##'],
                        ['##lang.projecttask.type##', '##projecttask.type##', 'projecttask.type'],
                        ['##lang.projecttask.planstartdate##', '##projecttask.planstartdate##'],
                        ['##lang.projecttask.planenddate##', '##projecttask.planenddate##'],
                        ['##lang.projecttask.plannedduration##', '##projecttask.plannedduration##'],
                        ['##lang.projecttask.effectiveduration##', '##projecttask.effectiveduration##'],
                    ]],
                    ['panel', '##lang.projecttask.description##', '##projecttask.description##'],
                ],
            ],
        ]);
    }

    /**
     * Mark these as GLPI's own.
     *
     * The settings page prints the plugin an entry came from, and "" is the
     * answer for core — written once here rather than repeated thirty-two
     * times above, where it would be thirty-two chances to forget.
     *
     * @param array<string,array<string,mixed>> $entries
     * @return array<string,array<string,mixed>>
     */
    private static function stamp(array $entries): array
    {
        foreach ($entries as $name => $entry) {
            $entries[$name]['plugin'] = '';
        }

        return $entries;
    }

    // ================================================================ the shapes

    /**
     * A ticket, a problem or a change.
     *
     * The three share a target base class and therefore a tag vocabulary —
     * `##ticket.priority##`, `##problem.priority##` and `##change.priority##`
     * are the same field with a different prefix. Writing them once means the
     * three most-read notifications in the instance cannot drift apart, which
     * they do the moment they are three copies.
     *
     * @param array<string,bool> $with
     * @return array<int,array<mixed>>
     */
    private static function itil(string $p, array $with = []): array
    {
        $n = Settings::loopLimit();

        $blocks = [
            ['eyebrow', "##$p.action##"],
            ['title', "##$p.title##"],
            self::statusPills($p),
        ];

        if ($with['approval'] ?? false) {
            // Status 5 is "solved": the requester is being asked to accept or
            // reject the solution, and the link that does that is a different
            // one from the ticket's own.
            $blocks[] = ['ifeq', "$p.storestatus", '5', [
                ['lede', 'This has been solved. Please confirm the solution below, or reject it and say why.'],
                ['button', 'Review the solution', "##$p.urlapprove##"],
            ], [
                ['button', 'Open it in the portal', "##$p.url##"],
            ]];
        } else {
            $blocks[] = ['button', 'Open it in the portal', "##$p.url##"];
        }

        $meta = [
            ["##lang.$p.status##", "##$p.status##"],
            ["##lang.$p.priority##", "##$p.priority##"],
            ["##lang.$p.category##", "##$p.category##", "$p.category"],
            ["##lang.$p.authors##", "##$p.authors##", "$p.authors"],
            ["##lang.$p.assigntousers##", "##$p.assigntousers##", "$p.assigntousers"],
            ["##lang.$p.assigntogroups##", "##$p.assigntogroups##", "$p.assigntogroups"],
            ["##lang.$p.creationdate##", "##$p.creationdate##"],
            ["##lang.$p.duedate##", "##$p.duedate##", "$p.duedate"],
            ["##lang.$p.entity##", "##$p.entity##"],
        ];

        if ($p === 'ticket') {
            // Only tickets carry an SLA and a request type.
            array_splice($meta, 2, 0, [
                ['##lang.ticket.requesttype##', '##ticket.requesttype##', 'ticket.requesttype'],
                ['##lang.ticket.sla_ttr##', '##ticket.sla_ttr##', 'ticket.sla_ttr'],
            ]);
        }

        $blocks[] = ['meta', $meta];
        // `content`, not `description`. Both tags exist; on GLPI 11.0.8
        // `##lang.ticket.content##` is "Description" and
        // `##lang.ticket.description##` is "Ticket: Description", which is a
        // sentence about a ticket rather than a label over its text. Read out
        // of a real render, not guessed.
        $blocks[] = ['panel', "##lang.$p.content##", "##$p.content##"];

        if ($with['items'] ?? false) {
            $blocks[] = ['if', "$p.numberofitems", [
                // Written out. `##lang.ticket.items##` is registered as a tag
                // and never assigned a value by NotificationTargetTicket, so
                // it renders as an empty heading over a populated list —
                // which looks like this plugin lost the label rather than like
                // GLPI never had one.
                ['section', 'Affected items'],
                ['loop', 'items', $n, [
                    ['meta', [
                        ["##$p.itemtype##", "##$p.item.name## ##$p.item.serial##"],
                    ]],
                ]],
            ]];
        }

        if ($with['analysis'] ?? false) {
            // A problem record's whole point is the analysis, and a stock
            // notification that omits it makes the mail a pointer to a page
            // rather than a thing worth reading.
            foreach (['symptoms', 'causes', 'impacts'] as $field) {
                $blocks[] = ['if', "$p.$field", [
                    ['panel', "##lang.$p.$field##", "##$p.$field##"],
                ]];
            }
        }

        if ($with['plans'] ?? false) {
            // The four plans an approver is being asked to sign off. Without
            // them the approval mail is "please approve something".
            foreach (
                [
                    'impactcontent'      => 'Impact',
                    'rolloutplancontent' => 'Rollout plan',
                    'backoutplancontent' => 'Backout plan',
                    'checklistcontent'   => 'Checklist',
                ] as $field => $label
            ) {
                $blocks[] = ['if', "$p.$field", [
                    ['panel', $label, "##$p.$field##"],
                ]];
            }
        }

        $blocks[] = ['if', "$p.solvedate", [
            // `solution.description`, whose label is simply "Solution" —
            // the one tag in this group that reads as a heading. `solvedate`
            // is "Date of solving" and `solution.type` is "Solution type",
            // both correct beside their field and wrong above the section.
            ['section', "##lang.$p.solution.description##"],
            ['meta', [
                ["##lang.$p.solvedate##", "##$p.solvedate##"],
                ["##lang.$p.solution.type##", "##$p.solution.type##"],
                ["##lang.$p.solution.author##", "##$p.solution.author##", "$p.solution.author"],
            ]],
            ['panel', '', "##$p.solution.description##"],
        ]];

        if ($with['timeline'] ?? false) {
            // The most recent entries, newest last, capped. A notification is
            // a summary with a link on it: a two-year-old ticket has ninety
            // followups and nobody has ever read the ninetieth mail.
            $blocks[] = ['if', "$p.numberoffollowups", [
                // Also written out: `##lang.ticket.timelineitems##` is
                // "Processing ticket", which is a status and not a heading for
                // the list of things that have happened.
                ['section', 'Recent activity'],
                ['loop', 'timelineitems', $n, [
                    ['meta', [
                        ['##timelineitems.date##', '##timelineitems.author## — ##timelineitems.typename##'],
                    ]],
                    ['panel', '', '##timelineitems.description##'],
                ]],
            ]];
        }

        return $blocks;
    }

    /**
     * The status pill.
     *
     * Green once there is a solve date, blue before. Deliberately one field
     * with one `##IF##` and one `##ELSE##` rather than a ladder over
     * `##…storestatus##`: {@see \NotificationTemplate::processIf()} matches
     * `##ENDIF<field>##` on the field name alone and rewrites one block per
     * occurrence in document order, so a ladder of four values against the same
     * field works only while nobody reorders it. A closed record has a solve
     * date too, so the two states this distinguishes are the two a reader
     * actually wants at a glance: still being worked, or done.
     *
     * @return array<mixed>
     */
    private static function statusPills(string $p): array
    {
        return ['pills', [
            ["#" . "##$p.id##", 'neutral'],
            ["##$p.status##", 'good', "$p.solvedate", "##$p.status##", 'info'],
        ]];
    }

    /**
     * A consumable or a cartridge that has run low.
     *
     * The two targets are the same shape with a different noun, down to the
     * `to_order` field, so they are one description. Note `##…url##`: both
     * targets assign it inside the loop but neither registers it as a tag, so
     * it is absent from the help panel and works perfectly well.
     *
     * @return array<int,array<mixed>>
     */
    private static function stockAlert(string $p, string $loop, string $title): array
    {
        return [
            ['eyebrow', "##$p.action##"],
            ['title', $title],
            ['pills', [['Below threshold', 'warn']]],
            ['meta', [
                ["##lang.$p.entity##", "##$p.entity##"],
            ]],
            ['loop', $loop, null, [
                ['divider'],
                ['link', "##$p.item##", "##$p.url##"],
                ['meta', [
                    ["##lang.$p.reference##", "##$p.reference##"],
                    ["##lang.$p.remaining##", "##$p.remaining##"],
                    ["##lang.$p.stock_target##", "##$p.stock_target##"],
                    ["##lang.$p.to_order##", "##$p.to_order##"],
                ]],
            ]],
        ];
    }

    /**
     * The satisfaction survey, for tickets and for changes.
     *
     * @return array<int,array<mixed>>
     */
    private static function satisfaction(string $p): array
    {
        return [
            ['eyebrow', "##$p.action##"],
            ['title', 'How did we do?'],
            ['lede', '##lang.satisfaction.text##'],
            ['button', 'Answer the survey', "##$p.urlsatisfaction##"],
            ['meta', [
                ["##lang.$p.title##", "##$p.title##"],
                ["##lang.$p.closedate##", "##$p.closedate##"],
            ]],
            ['note', 'It takes about a minute, and it is read.'],
        ];
    }
}
