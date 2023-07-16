function wfs_onboarding_get_user_id(user_login) {
  let data = new URLSearchParams();
  data.append('action', 'wfs_onboarding_check_user_login');
  data.append('user_login', user_login);
  data.append('nonce', wfs_onboarding_get_nonce_data.nonce);

  return fetch(wfs_onboarding_get_nonce_data.ajax_url, {
    method: 'POST',
    body: data
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      return data.data;
    } else {
      return false;
    }
  })
  .catch((error) => {
    console.error('Error:', error);
    return false;
  });
}

// Get references to the fields
let emailField = document.querySelector('#form-field-proposal_contact_email');
let userIdField = document.querySelector('#form-field-user_id');

// Attach event listeners
emailField.addEventListener('change', updateUserId);
emailField.addEventListener('blur', updateUserId);
emailField.addEventListener('keyup', updateUserId);

// Callback function
async function updateUserId() {
  let email = emailField.value;
  let user_id = await wfs_onboarding_get_user_id(email);
  userIdField.value = user_id;
}

document.addEventListener('DOMContentLoaded', (event) => {
  // Define a function that checks if the form is visible and sets the userIdField value.
  const checkFormAndSetUserId = () => {
    let form = document.querySelector('#proposal');
    let emailField = document.querySelector('#form-field-proposal_contact_email');
    let userIdField = document.querySelector('#form-field-user_id');

    if (form && emailField.value && getComputedStyle(form).display !== 'none') {
      wfs_onboarding_get_user_id(emailField.value).then(user_id => {
        userIdField.value = user_id;
      });
    }
  };

  // Call the function once when the page has loaded.
  checkFormAndSetUserId();

  // Set up a MutationObserver to call the function when the visibility of the form changes.
  let observer = new MutationObserver(checkFormAndSetUserId);
  let config = { attributes: true, childList: true, subtree: true }; // Configuration of the observer: in this case, looking for changes in the 'display' attribute.
  observer.observe(document.body, config); // Pass in the target node (body) and the observer options.
});
