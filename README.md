# code-example-mailing-list
This is a small sample of some code that I had recently written.
The code here is only a small snippet containing an example form and part of the script that is used to process the form data.

The full application also requires a custom database query class and security class that I had previously written to handle CRUD functionality.
It also requires access to a much larger codebase that I had developed.
This codebase houses all the backend tools and interfaces that allows administrators to handle data & reporting from within a clean GUI.

# What does it do?
The full application allows for any external form to push subscribers to a loyalty system & mailing list.
The mailing list can have an optional welcome email that will be processed upon sign up.

The mailing list can then be accessed from within the main system and newsletters can be created & scheduled to run at later dates. (Similar to Mailchimp)
Newsletters also track deliveries and the whole process is captured in a log to compare targets hit & targets missed.

The form processing script will lookup the subscribers state based on postcode and also assign them to their closest store based on the Longitude & Latitude of their supplied postcode.

Each new subscriber is also sent login details to a custom loyalty member portal (Microsite)

# ** Example code only
Please note this is sample code only and not a full working example.
The purpose is to give potential employers a look a some code I have previously written.

