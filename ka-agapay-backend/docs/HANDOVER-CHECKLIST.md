# Ka-Agapay — Handover Checklist

**For RHU1 & RHU2 administration and IT staff, Malasiqui, Pangasinan.**

This is the non-technical companion to `docs/OPERATIONS.md`. It covers the things
that are **not** in the code: who holds the accounts, what the system costs to
run each month, what has to happen on a schedule, and who to call.

> **Fill in every blank marked `__________` before the original development team
> becomes unreachable.** Every blank left empty is a single point of failure with
> a person's name on it.

---

## 1. Accounts and ownership

The system depends on seven external accounts. If nobody at the RHU can log into
one of these, that part of the system cannot be fixed, renewed, or paid for.

For each: record who holds it, and make sure **at least two people** at the RHU
can access it — one of whom is a permanent staff member, not a student.

| Account | What breaks without it | Account holder | Backup holder |
|---|---|---|---|
| **DigitalOcean** (droplet + backups) | Everything. This is the server. | `__________` | `__________` |
| **Domain registrar** | The address stops resolving; certificates cannot renew | `__________` | `__________` |
| **Semaphore** (SMS) | All SMS: reminders, alerts, credentials | `__________` | `__________` |
| **Google AI Studio** (Gemini) | The AI chat assistant | `__________` | `__________` |
| **8x8 JaaS** (video) | Telemedicine calls and Team Chat calls | `__________` | `__________` |
| **OCR.space** | PhilHealth / employee ID scanning | `__________` | `__________` |
| **Expo / EAS + Play Store + App Store** | Mobile app updates | `__________` | `__________` |
| **GitHub** (source code) | Nobody can change or redeploy the system | `__________` | `__________` |

> ### ⛔ The domain is the quiet one
>
> Domain registration usually renews annually and is easy to forget. If it
> lapses, the website and the mobile app both stop working and the HTTPS
> certificate cannot renew. **Record the renewal date here:** `__________`
> — and set a calendar reminder 60 days before.

### Credentials

- Credentials must **never** be stored in the source code repository, in email,
  or in a chat message.
- Use a shared password manager, or a sealed written record held by the RHU
  administrator.
- **Where are the credentials kept?** `__________`
- **Who can open that?** `__________`

---

## 2. What it costs to run

Approximate monthly figures, from the project's original costing. **Verify
against real invoices before budgeting** — usage-based costs move.

| Service | Approx. monthly | Notes |
|---|---|---|
| **Semaphore SMS** | **₱23,180** | **By far the largest cost.** Usage-based — it rises with patient volume and with every reminder the system sends. |
| Gemini API | ₱1,805 | Usage-based (AI chat assistant) |
| DigitalOcean droplet | ₱1,640 | Fixed |
| Database hosting | ₱870 | Now on the same droplet — verify whether this is still billed separately |
| OCR.space | ₱290 | Usage-based |
| Firebase / push notifications | ₱0 | Free tier |
| **Approximate total** | **≈ ₱27,800/month** | |

**Not yet in this table — two costs this hardening pass introduces:**

| Service | Approx. monthly | Why |
|---|---|---|
| DigitalOcean droplet backups | ~20% of droplet cost (≈ ₱330) | Whole-machine recovery |
| Object storage for nightly database backups | ≈ ₱300 | Keeps a copy of the database **off** the server |

> ### SMS is the cost that will surprise you
>
> Semaphore is roughly **83% of the monthly bill**, it is prepaid, and when the
> balance runs out **SMS simply stops** — no error, no alert to patients, and the
> system looks like it is working. Whoever holds the Semaphore account should
> check the balance weekly (§4).
>
> **Who is responsible for topping it up?** `__________`
> **From which budget line?** `__________`

---

## 3. Before this counts as handed over

Work through this with whoever has server access. Nothing here can be done from
the source code alone.

### Must be true before handover is real

- [ ] **Nightly database backups are installed and running.** Confirm the web
      admin's *Settings → Backup* panel shows **"Database is protected"**. If it
      says *"No backups recorded yet"*, the job is not installed.
- [ ] **At least one restore has been tested.** A backup nobody has restored is a
      guess. Date of last successful restore drill: `__________`
- [ ] **Backups are stored somewhere other than the server.** A copy that lives
      only on the server does not survive that server failing. If the panel says
      *"Backup has not left the server"*, this is not done.
- [ ] **The scheduled-jobs entry is confirmed present on the server.** Without it,
      appointment reminders, follow-ups, event SMS and stock alerts silently never
      send. This is the single most commonly missed item.
- [ ] **The HTTPS certificate renews automatically**, and renewal has been tested
      — not just assumed. Certificates expire every 90 days and take down the
      website and mobile app together.
- [ ] **Video calling has been tested on real devices** — a patient on a phone
      and a doctor on a computer, in the same call.
- [ ] **Error alerts reach a real person.** A messaging webhook is configured so a
      failed SMS batch or a failed backup notifies someone the same day.
      Alerts go to: `__________`
- [ ] **At least two people can access every account in §1.**
- [ ] **At least one RHU staff member has walked through `docs/OPERATIONS.md`**
      with the outgoing team, on the actual server.

### Should be scheduled, not just noted

- [ ] Domain renewal reminder set (60 days ahead)
- [ ] Semaphore balance check added to someone's weekly routine
- [ ] Quarterly certificate + restore drill added to a calendar

---

## 4. Routine maintenance — who does what, how often

Assign a **named person** to each row. "IT will handle it" is not an assignment.

| How often | Task | Takes | Assigned to |
|---|---|---|---|
| **Weekly** | Check the Semaphore SMS balance and top up if low | 5 min | `__________` |
| **Weekly** | Open *Settings → Backup* and confirm it says "Database is protected" | 1 min | `__________` |
| **Monthly** | Run the two security-scan commands in `OPERATIONS.md` §9 | 10 min | `__________` |
| **Quarterly** | Test certificate renewal; restore a backup into a test database | 1 hour | `__________` |
| **Annually** | Renew the domain; review whether the software needs updating | — | `__________` |

---

## 5. Known limitations — please read before promising anything

These are honest, current limits of the system. They are not faults to be
reported; they are things to plan around.

- **The system needs a working internet connection.** There is no offline mode.
  BHWs doing barangay visits in low-coverage areas cannot record anything on the
  spot — this is documented future work.
- **SMS messages are summaries only** (schedules, reminders). They never contain
  medical detail, by design and by data-privacy policy.
- **Telemedicine is for non-emergency consultations only.** It does not replace
  physical examination, and the queue system does not replace a clinician's
  professional judgment.
- **The underlying framework is past its security-support window.** It works, but
  it will not receive further security patches. Budget for an upgrade project —
  see `OPERATIONS.md` §9 for scope. This is not urgent-today, but it should not
  be left indefinitely.
- **The mobile app's dependencies are behind** and need a major update before the
  app stores stop accepting new builds.

---

## 6. Who to contact

### Original development team

| Name | Role | Contact | Available until |
|---|---|---|---|
| `__________` | `__________` | `__________` | `__________` |
| `__________` | `__________` | `__________` | `__________` |
| `__________` | `__________` | `__________` | `__________` |

### If the original team is unreachable

Any competent Laravel + React developer can maintain this system. What they need
on day one:

1. **`docs/OPERATIONS.md`** in the backend repository — deployment, configuration,
   backup, recovery, and a symptom-to-cause table.
2. **`.env.example`** — every configuration value the system needs, with notes on
   what each one controls.
3. **Access to the accounts in §1** — without these, no developer can help,
   however skilled.

**Skills to ask for when hiring:** PHP / Laravel, PostgreSQL, React, basic Linux
server administration (Nginx, cron, Certbot).

> The single most valuable thing you can do for whoever comes next is to keep
> §1 filled in and current. Code can be read. Lost account access cannot be.

---

## 7. Escalation — what to do first when something is wrong

| What staff report | First thing to check | Then |
|---|---|---|
| "No SMS is being sent" | Semaphore balance | `OPERATIONS.md` §10 |
| "Reminders stopped coming" | The scheduled-jobs entry | `OPERATIONS.md` §5 |
| "Video calls won't connect" | Video credentials | `OPERATIONS.md` §10 |
| "The whole site is down" | Certificate expiry, then server status | `OPERATIONS.md` §6, §10 |
| "The backup panel is red" | Whether the backup job is still installed | `OPERATIONS.md` §7 |
| Anything involving lost patient data | **Stop. Do not delete or re-enter anything.** Contact whoever holds server access immediately — the system keeps deleted records recoverable, but only if nobody overwrites them first. | `OPERATIONS.md` §7 |

---

*Prepared 1 September 2026, during the pre-handover hardening pass.
Technical detail: `docs/OPERATIONS.md`.*
