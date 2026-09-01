/**
 * Tool and resource modules self-register on import. This barrel is the single
 * import site used by the server bootstrap; adding a module = adding a line.
 */
import "../resources/entities.js";
import "./organization.js";
import "./extensions.js";
import "./phone-numbers.js";
import "./ring-groups.js";
import "./ivr.js";
import "./business-hours.js";
import "./conference-rooms.js";
import "./ai.js";
import "./calls.js";
import "./active-calls.js";
import "./campaigns.js";
import "./distribution-lists.js";
import "./security-lists.js";
import "./users.js";
import "./reporting.js";
import "./lifecycle.js";
import "./validation.js";
import "./deletes.js";
