# Northwind Cloud Incident Response Runbook

_Fictional sample document used by the DocQA retrieval evaluation._

This runbook covers outages and degradations of the Northwind Cloud fleet-management platform that customers use to control their robots.

## Severity levels

- **SEV1:** the platform is down for all customers, or robots cannot receive commands. Page the on-call engineer and the incident commander immediately, at any hour.
- **SEV2:** a major feature is broken or a large group of customers is affected, but a workaround exists. Page the on-call engineer; the incident commander joins during business hours.
- **SEV3:** a minor bug or a single customer is affected. Create a ticket in the INC queue; no page is needed.

## First 15 minutes

The first responder acknowledges the page within 5 minutes, opens an incident channel named #inc-YYYYMMDD-short-name, and posts the current impact. For SEV1 and SEV2, the first status page update must be published within 15 minutes of the page, even if the cause is still unknown.

## Roles

The incident commander coordinates the response and decides when to escalate; they do not debug. The communications lead updates the status page every 30 minutes during a SEV1 and every hour during a SEV2. The subject-matter experts investigate and propose fixes.

## Rollbacks

If a deployment in the last two hours is a plausible cause, roll it back first and investigate afterwards. Rollbacks are done with the deploy tool using the command `ship rollback --to previous`. Database migrations are never rolled back automatically; they need sign-off from the data team.

## Customer credits

Customers on the Enterprise plan receive service credits when monthly availability drops below 99.9 percent: 10 percent of the monthly fee below 99.9 percent and 25 percent below 99.0 percent. Credits are applied automatically on the next invoice.

## Post-incident review

Every SEV1 and SEV2 incident gets a blameless post-incident review within five working days. The review lists the timeline, the root cause, what went well, and action items with owners. Action items from SEV1 reviews must be completed within 30 days.
