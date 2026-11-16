// Draws the API reference at /api/docs from the spec the API serves. SwaggerUIBundle is
// the global swagger-ui-bundle.js defines, loaded just before this file. The validator
// badge is off: it would hand the spec's address to validator.swagger.io.
/* global SwaggerUIBundle */
SwaggerUIBundle({
  url: '/api/openapi.json',
  dom_id: '#reference',
  deepLinking: true,
  validatorUrl: null,
})
