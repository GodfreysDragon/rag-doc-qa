# Northwind Robotics IT Security Policy

_Fictional sample document used by the DocQA retrieval evaluation._

This policy applies to everyone with a Northwind account, including contractors.

## Passwords and sign-in

Passwords must be at least 14 characters long and must not be reused from other services. Use the company password manager, Vaultline, to generate and store them. Multi-factor authentication is mandatory for email, the code repository and the VPN; hardware security keys are issued to engineers with production access.

## Devices

Company laptops must have full-disk encryption and automatic screen lock after 5 minutes of inactivity. Personal phones may be used for email and chat only after installing the device-management profile. Do not install software from outside the internal app catalogue without approval from IT.

If a laptop or phone with company data is lost or stolen, report it to the security team within 24 hours so it can be wiped remotely. Reporting quickly matters more than knowing exactly what was on the device.

## Phishing

Report suspicious emails with the "Report phishing" button in the mail client. Security runs simulated phishing campaigns four times a year; clicking a simulated link only leads to a short training, never to disciplinary action.

## Access to production

Production access is granted for at most 12 hours at a time through the access broker and requires a ticket reference. All production sessions are recorded. Shared accounts are not allowed.

## Data classification

Data is classified as Public, Internal, Confidential or Restricted. Customer robot telemetry is Confidential. Payment data and health data are Restricted and may only be stored in the dedicated vault environment. Confidential and Restricted data must never be pasted into external AI tools.

## Security incidents

Suspected security incidents are reported in the #security channel or by phone to the security on-call. The security team decides whether an incident must be reported to the data protection authority, which has to happen within 72 hours of becoming aware of a personal data breach.
