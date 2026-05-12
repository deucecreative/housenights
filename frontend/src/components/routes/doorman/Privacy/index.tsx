import { useEffect } from "react";

const Privacy = () => {
    useEffect(() => {
        document.title = "Doorman — Privacy Policy";
    }, []);

    return (
        <main style={{ maxWidth: 760, margin: "2rem auto", padding: "0 1rem", lineHeight: 1.6 }}>
            <h1>Doorman — Privacy Policy</h1>
            <p style={{ color: "#666" }}>Last updated: 12 May 2026</p>

            <p>
                This privacy policy describes how the Doorman mobile app ("Doorman", "the app"),
                operated by Deuce Creative Ltd / House Nights ("we", "us"), handles your information.
            </p>

            <h2>Who Doorman is for</h2>
            <p>
                Doorman is a tool for venue staff and event organisers to scan and check in guests
                at ticketed events. It is not a consumer ticketing app. You use Doorman because an
                authorised House Nights account has given you access.
            </p>

            <h2>What information we collect</h2>

            <p>
                <strong>Account information you provide</strong>
                <br />
                When you log in, you give us your email address and password. These are sent to
                our servers at api.housenights.co.uk over HTTPS and used solely to authenticate
                your access to the events you have been assigned to.
            </p>

            <p>
                <strong>Event and guest data we fetch on your behalf</strong>
                <br />
                Once you are logged in, the app fetches lists of attendees for the events you
                have access to. These lists include attendees' names, email addresses, ticket
                type, and check-in status. This information is stored on your device so the app
                can work offline.
            </p>

            <p>
                <strong>Camera input</strong>
                <br />
                Doorman uses your device's camera only to scan QR codes and barcodes printed on
                guest tickets. Camera frames are processed on your device in real time. We do
                not record, save, or transmit camera images or video.
            </p>

            <p>
                <strong>Check-in records you create</strong>
                <br />
                When you check a guest in or out, the app records the action, the time, and the
                attendee involved, and sends it to api.housenights.co.uk so the rest of your
                team's devices can stay in sync.
            </p>

            <h2>What we do not collect</h2>
            <p>
                Doorman contains no third-party analytics, advertising, or tracking SDKs. We do
                not collect your location, your contacts, your microphone audio, your device
                identifiers for advertising, or any data unrelated to event check-in.
            </p>

            <h2>How information is stored</h2>
            <ul>
                <li>
                    Login credentials are sent to our servers over HTTPS and never stored in the
                    app beyond what is needed to maintain your session.
                </li>
                <li>
                    A short-lived authentication token is stored on your device so you do not have
                    to log in repeatedly.
                </li>
                <li>
                    Event and attendee data is cached in a local database on your device so you
                    can keep checking guests in if the venue's internet connection drops. This
                    cache is cleared when you log out.
                </li>
                <li>
                    Check-in records are stored on our servers for the duration of the event and
                    for a reasonable period afterwards for reporting and reconciliation.
                </li>
            </ul>

            <h2>Who we share information with</h2>
            <p>
                We do not sell or share your information with third parties for advertising or
                marketing.
            </p>
            <p>
                We share check-in records and attendee data only with the House Nights account
                that owns the event you are working on, and with the platforms that legitimately
                host our backend infrastructure on our behalf.
            </p>
            <p>We may disclose information if required by law.</p>

            <h2>Your rights</h2>
            <p>
                You can request access to, correction of, or deletion of personal data we hold
                about you by emailing <a href="mailto:info@deucecreative.co.uk">info@deucecreative.co.uk</a>.
                If you are a guest whose details appear in Doorman because you bought a ticket,
                please contact the event organiser who sold you the ticket in the first instance.
            </p>

            <h2>Children</h2>
            <p>
                Doorman is intended for use by adult event staff. It is not directed at children.
            </p>

            <h2>Changes to this policy</h2>
            <p>
                We may update this policy from time to time. The "Last updated" date at the top
                will reflect the most recent revision. Material changes will be communicated via
                our normal staff channels.
            </p>

            <h2>Contact</h2>
            <p>
                Deuce Creative Ltd / House Nights
                <br />
                <a href="mailto:info@deucecreative.co.uk">info@deucecreative.co.uk</a>
            </p>
        </main>
    );
};

export default Privacy;
