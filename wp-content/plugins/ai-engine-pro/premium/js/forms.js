const { render } = wp.element;
import FormField from './forms/FormField';
import FormSubmit from './forms/FormSubmit';
import FormOutput from './forms/FormOutput';

function decodeHtmlEntities(encodedStr) {
  if (!encodedStr) {
    return "{}";
  }
  const textarea = document.createElement('textarea');
  textarea.innerHTML = encodedStr;
  return textarea.value;
}

document.addEventListener('DOMContentLoaded', function() {

  function processContainers(containers, Component) {
    containers.forEach((container) => {
      let params = JSON.parse(decodeHtmlEntities(container.getAttribute('data-params')));
      let system = JSON.parse(decodeHtmlEntities(container.getAttribute('data-system')));
      let theme = JSON.parse(decodeHtmlEntities(container.getAttribute('data-theme')));
      container.removeAttribute('data-params');
      container.removeAttribute('data-system');
      container.removeAttribute('data-theme');
      render(wp.element.createElement(Component, { system, params, theme }), container);
    });
  }
  
  const formFields = document.querySelectorAll('.mwai-form-field-container');
  processContainers(formFields, FormField);

  const submitFields = document.querySelectorAll('.mwai-form-submit-container');
  processContainers(submitFields, FormSubmit);

  const outputFields = document.querySelectorAll('.mwai-form-output-container');
  processContainers(outputFields, FormOutput);
});
