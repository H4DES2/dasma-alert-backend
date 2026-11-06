<?php
/**
 * Seed data for the triage question library.
 *
 * Levels: 0 = no escalation, 1 = Minor, 2 = Major, 3 = Critical.
 *
 * Link notation:
 *   "Medical"                      -> every incident in that emergency type (also future ones)
 *   "Medical:Stroke"               -> only that one incident
 *
 * Final severity of a report = max(base level of the incident,
 *                                  level of every chosen option,
 *                                  "unsure" level of every linked question left unanswered).
 */

$BASE_MAJOR = [
    'Medical'       => ['Heart Attack / Cardiac Arrest', 'Severe Bleeding / Trauma', 'Stroke', 'Vehicular Accident Injury'],
    'Fire'          => ['Residential / House Fire', 'Commercial / Building Fire', 'Industrial Plant Fire', 'LPG Leak / Explosion'],
    'Crime'         => ['Domestic Violence', 'Weapon / Gun Sighting'],
    'Rescue'        => ['Flood / Trapped in Water', 'Trapped in Vehicle (Extrication)', 'Collapsed Structure', 'Fell in Drainage / Manhole', 'River / Stream Distress'],
    'Hazard'        => ['Downed Power Lines / Post'],
    'Accident'      => ['Multiple Vehicle Pileup', 'Overturned Heavy Truck'],
    'Environmental' => ['Hazardous Chemical Leak (Industrial)'],
    'Health'        => ['Mass Respiratory Distress'],
];

$QUESTIONS = [];
function q(string $text, int $unsure, array $opts, array $links): void {
    global $QUESTIONS;
    $QUESTIONS[] = ['text' => $text, 'unsure' => $unsure, 'opts' => $opts, 'links' => $links];
}

/* ------------------------------ MEDICAL ------------------------------ */
q('Is the person awake and responding?', 2,
  ['Yes, talking normally=0', 'Confused or drowsy=2', 'Not responding=3'],
  ['Medical', 'Rescue:Fell in Drainage / Manhole', 'Rescue:Trapped in Vehicle (Extrication)',
   'Accident:Motorcycle / Bicycle Crash', 'Accident:Pedestrian Hit-and-Run']);
q('Is the person breathing normally?', 2,
  ['Yes=0', 'Struggling or gasping=3', 'Not breathing=3'], ['Medical']);
q('Did they hit their head when they fell?', 2,
  ['No=0', 'Yes=2'], ['Medical:Unconscious / Fainted Person']);
q('Does the bleeding stop with firm pressure?', 2,
  ['Yes, it is slowing or stopped=2', 'No, it keeps soaking through=3'], ['Medical:Severe Bleeding / Trauma']);
q('Where is the wound?', 2,
  ['Arm or leg=2', 'Head, chest or belly=3'], ['Medical:Severe Bleeding / Trauma']);
q('Which best describes their breathing?', 2,
  ['A bit hard, but speaking full sentences=1', 'Speaking only a few words=2', 'Cannot speak, gasping, or lips turning blue=3'],
  ['Medical:Breathing Difficulty']);
q('Is any of this happening right now: face drooping, slurred speech, or one arm weak?', 3,
  ['Yes=3', 'No, it came and went=2'], ['Medical:Stroke']);
q('Is the skin hot and dry, with sweating stopped?', 2,
  ['Yes=3', 'No, still sweating=1'], ['Medical:Heat Stroke / Exhaustion']);
q('Are they vomiting or too weak to stand?', 0,
  ['No=0', 'Yes=2'], ['Medical:Heat Stroke / Exhaustion']);
q('Is the seizure still happening, or did it last over 5 minutes?', 2,
  ['No, it has stopped=1', 'Yes=3'], ['Medical:Seizure / Convulsion']);
q('Is this their first seizure?', 2,
  ['No, known condition=0', 'Yes, first time=2'], ['Medical:Seizure / Convulsion']);
q('How many people are hurt?', 0,
  ['Just one=0', '2 to 4=2', '5 or more=3'], ['Medical:Vehicular Accident Injury']);
q('Is anyone bleeding heavily?', 2,
  ['No=0', 'Yes=3'],
  ['Medical:Vehicular Accident Injury', 'Accident:Motorcycle / Bicycle Crash', 'Accident:Pedestrian Hit-and-Run']);
q('Is the person in severe pain, or is a broken bone suspected?', 0,
  ['No=0', 'Yes=2'], ['Medical:Other Medical Emergency']);

/* -------------------------------- FIRE -------------------------------- */
q('Is anyone trapped, hurt or unaccounted for?', 2,
  ['Everyone is safe and out=0', 'Someone is hurt=2', 'Someone is trapped or missing=3'], ['Fire']);
q('Is the fire spreading to other buildings?', 0,
  ['No, it is contained=0', 'Yes=3'],
  ['Fire:Residential / House Fire', 'Fire:Commercial / Building Fire', 'Fire:Industrial Plant Fire']);
q('Are there gas tanks, fuel or chemicals nearby?', 2,
  ['No=0', 'Yes=3'],
  ['Fire:Residential / House Fire', 'Fire:Commercial / Building Fire', 'Fire:Industrial Plant Fire']);
q('Is the vehicle near a fuel station or buildings?', 2,
  ['No=0', 'Yes=3'], ['Fire:Vehicle Fire']);
q('What can you see on the vehicle?', 2,
  ['Smoke only, no flames=1', 'Flames=2'], ['Fire:Vehicle Fire']);
q('How big is the fire?', 2,
  ['Small patch=1', 'Large area=2'], ['Fire:Grass / Brush Fire']);
q('Are houses close by (within about 50 m)?', 2,
  ['No, nothing nearby=0', 'Yes, houses nearby=2', 'It is reaching houses, or people are evacuating=3'],
  ['Fire:Grass / Brush Fire']);
q('What do you see on the post or wire?', 2,
  ['Sparks only=1', 'Burning wire or post=2'], ['Fire:Electrical Post / Wire Fire']);
q('Is the fire reaching houses, or did anyone get shocked?', 2,
  ['No=0', 'Yes=3'], ['Fire:Electrical Post / Wire Fire']);
q('What is happening with the gas?', 2,
  ['Gas smell only, no fire=2', 'Fire has started or it exploded=3'], ['Fire:LPG Leak / Explosion']);
q('Is anyone dizzy or hurt from the gas?', 0,
  ['No=0', 'Yes=3'], ['Fire:LPG Leak / Explosion']);
q('Is the fire still growing?', 0,
  ['No, it is under control=0', 'Yes, it is growing=2'], ['Fire:Other Fire Incident']);

/* ------------------------------- CRIME ------------------------------- */
q('Is anyone hurt?', 0,
  ['No=0', 'Yes, minor injuries=1', 'Yes, serious injury=3'], ['Crime']);
q('Is it still happening right now?', 2,
  ['No, it has ended=0', 'Yes=2'],
  ['Crime:Robbery / Hold-up', 'Crime:Physical Assault / Riot', 'Crime:Domestic Violence',
   'Crime:Vandalism / Property Damage', 'Crime:Other Crime']);
q('Is there a weapon?', 2,
  ['None seen=0', 'Knife or blunt object=2', 'Gun=3'],
  ['Crime:Robbery / Hold-up', 'Crime:Physical Assault / Riot', 'Crime:Domestic Violence',
   'Crime:Illegal Drugs Activity', 'Crime:Suspicious Person / Activity']);
q('How many people are involved?', 0,
  ['1 or 2=0', 'A group of 3 to 9=2', 'A crowd of 10 or more=3'], ['Crime:Physical Assault / Riot']);
q('Are there children in the home?', 0,
  ['No=0', 'Yes=2'], ['Crime:Domestic Violence']);
q('Was violence or a weapon used?', 0,
  ['No=0', 'Yes=2'], ['Crime:Theft / Pickpocket']);
q('Is the person threatening or harming anyone?', 0,
  ['No, just watching or loitering=0', 'Yes=2'], ['Crime:Suspicious Person / Activity']);
q('Is a group involved, or was a fire or hazard started?', 0,
  ['No=0', 'Yes=2'], ['Crime:Vandalism / Property Damage']);
q('What is the person doing with the weapon?', 2,
  ['Carrying or holding it, nobody threatened=2', 'Pointing it at someone, or shots fired=3'],
  ['Crime:Weapon / Gun Sighting']);
q('Is it a crowded place (school, market, church)?', 0,
  ['No=0', 'Yes=3'], ['Crime:Weapon / Gun Sighting']);
q('Are minors involved, or is there violence?', 0,
  ['No=0', 'Yes=2'], ['Crime:Illegal Drugs Activity']);

/* ------------------------------ RESCUE ------------------------------ */
q('Is any person in immediate danger?', 0,
  ['No=0', 'Yes=2'], ['Rescue']);
q('How deep is the water around the person?', 2,
  ['Knee to waist deep, holding on safely=2', 'Chest-high or higher, or being swept away=3'],
  ['Rescue:Flood / Trapped in Water']);
q('How many people need rescue?', 0,
  ['Just one=0', '2 to 4=2', '5 or more=3'],
  ['Rescue:Flood / Trapped in Water', 'Rescue:Collapsed Structure', 'Rescue:River / Stream Distress', 'Rescue:Other Rescue']);
q('Any fire, fuel leak, or is the vehicle in water?', 2,
  ['No=0', 'Yes=3'], ['Rescue:Trapped in Vehicle (Extrication)']);
q('Is anyone buried or trapped?', 3,
  ['No one trapped, but the structure is unstable=2', 'Someone is buried or trapped=3'],
  ['Rescue:Collapsed Structure']);
q('Who fell?', 2,
  ['An adult=2', 'A child or elderly person=3'], ['Rescue:Fell in Drainage / Manhole']);
q('Is water flowing or rising in the drain?', 2,
  ['No=0', 'Yes=3'], ['Rescue:Fell in Drainage / Manhole']);
q('Is anyone ill, pregnant or a child?', 0,
  ['No=0', 'Yes=2'], ['Rescue:Elevator Trap']);
q('Any smoke or burning smell?', 0,
  ['No=0', 'Yes=3'], ['Rescue:Elevator Trap']);
q('How long have they been stuck?', 0,
  ['Under 30 minutes=0', 'Over 30 minutes=2'], ['Rescue:Elevator Trap']);
q('Where is the person?', 3,
  ['On the bank or a rock, safe for now=2', 'In the water, struggling or swept away=3'],
  ['Rescue:River / Stream Distress']);
q('Has anyone been bitten or attacked?', 0,
  ['No=0', 'Yes=2'], ['Rescue:Animal Rescue']);

/* ------------------------------ HAZARD ------------------------------ */
q('Is anyone hurt or in immediate danger because of this?', 0,
  ['No=0', 'Yes=3'], ['Hazard']);
q('Are the wires sparking or in water?', 2,
  ['Sparking, or lying in water=3', 'Quiet, on dry ground=2'], ['Hazard:Downed Power Lines / Post']);
q('How much of the road is blocked by the tree?', 1,
  ['Partly, traffic can pass=1', 'Fully blocked=2'], ['Hazard:Fallen Tree Blocking Road']);
q('Are power lines tangled in it?', 0,
  ['No=0', 'Yes=2'], ['Hazard:Fallen Tree Blocking Road']);
q('Where is the open manhole?', 1,
  ['Sidewalk or quiet street=1', 'Busy road, school zone or crowded area=2'], ['Hazard:Open / Uncovered Manhole']);
q('How big is the spill?', 1,
  ['Small and contained=1', 'Large or spreading=2'], ['Hazard:Oil / Chemical Spill']);
q('Strong fumes, or anyone feeling ill?', 0,
  ['No=0', 'Strong fumes noticed=2', 'Someone feels ill=3'], ['Hazard:Oil / Chemical Spill']);
q('Is there a flame, spark or heat source nearby?', 0,
  ['No=0', 'Yes=3'], ['Hazard:Oil / Chemical Spill']);
q('What is the situation with the slope?', 3,
  ['Small slide, nobody near=1', 'Still moving, houses nearby or road cut=2', 'It has hit houses=3'],
  ['Hazard:Landslide / Soil Erosion']);
q('Are any buildings near the edge?', 2,
  ['None nearby=1', 'Yes, buildings near the edge=2', 'A structure is already failing or leaning=3'],
  ['Hazard:Riverbank Scouring / Ground Failure']);
q('Are cracks widening or the ground still shifting?', 0,
  ['No=0', 'Yes=2'], ['Hazard:Riverbank Scouring / Ground Failure']);
q('Where is the pole leaning?', 1,
  ['Away from the road and houses=1', 'Over a road or houses=2', 'It is falling, or sparking=3'],
  ['Hazard:Unsafe Leaning Utility Pole']);
q('Could it get worse or hurt someone soon?', 0,
  ['No=0', 'Yes=2'], ['Hazard:Other Hazard']);

/* ------------------------------ ACCIDENT ------------------------------ */
q('Is anyone injured?', 2,
  ['No one is hurt=0', 'Minor injuries (awake, can walk)=1',
   'Serious injury (heavy bleeding or cannot move)=2', 'Someone is unconscious or not breathing=3'],
  ['Accident']);
q('Is anyone trapped inside a vehicle?', 0,
  ['No=0', 'Yes=3'],
  ['Accident:Vehicular Collision (Car/SUV)', 'Accident:Multiple Vehicle Pileup', 'Accident:Overturned Heavy Truck']);
q('Is there fire, smoke or a fuel leak?', 0,
  ['No=0', 'Yes=3'],
  ['Accident:Vehicular Collision (Car/SUV)', 'Accident:Multiple Vehicle Pileup', 'Accident:Road Obstruction Crash']);
q('Is the road blocked?', 0,
  ['Not blocked=0', 'Partly blocked=1', 'Fully blocked=2'],
  ['Accident:Vehicular Collision (Car/SUV)', 'Accident:Overturned Heavy Truck', 'Accident:Road Obstruction Crash']);
q('How many vehicles are involved?', 2,
  ['3 to 5=2', '6 or more=3'], ['Accident:Multiple Vehicle Pileup']);
q('Is fuel or cargo spilling, or is there fire?', 0,
  ['No=0', 'Yes=3'], ['Accident:Overturned Heavy Truck']);

/* --------------------------- ENVIRONMENTAL --------------------------- */
q('Are people stranded, hurt or in danger?', 0,
  ['No=0', 'Yes=3'], ['Environmental']);
q('Where is the water now?', 2,
  ['Still within the banks=1', 'Over the banks, on roads or near homes=2', 'Already inside homes=3'],
  ['Environmental:Severe River Overflow']);
q('Is the water rising?', 2,
  ['No, stable=0', 'Yes, rising=2', 'Yes, rising fast=3'],
  ['Environmental:Severe River Overflow', 'Environmental:Flash Flood / Water Inundation']);
q('How deep is the water?', 2,
  ['Ankle-deep=1', 'Knee-deep=2', 'Waist-deep or higher=3'], ['Environmental:Flash Flood / Water Inundation']);
q('Are homes, a school or a market close to the site?', 2,
  ['No, contained at the site=0', 'Yes=3'], ['Environmental:Hazardous Chemical Leak (Industrial)']);
q('Is anyone having trouble breathing or feeling sick?', 2,
  ['No=0', 'Yes=3'],
  ['Environmental:Hazardous Chemical Leak (Industrial)', 'Environmental:Dense Smoke / Toxic Emission']);
q('How wide is the affected area?', 1,
  ['One house or spot=1', 'A whole street or more=2'], ['Environmental:Dense Smoke / Toxic Emission']);
q('Is heavy smoke drifting over homes?', 0,
  ['No=0', 'Yes=2'], ['Environmental:Illegal Waste Burning / Dumping']);
q('Is hazardous waste involved (chemicals, plastics, medical waste)?', 0,
  ['No=0', 'Yes=2'], ['Environmental:Illegal Waste Burning / Dumping']);

/* ------------------------------- HEALTH ------------------------------- */
q('Is anyone severely ill (trouble breathing, collapsing, heavy bleeding)?', 0,
  ['No=0', 'Yes=3'], ['Health']);
q('How many people are sick?', 1,
  ['A few (2 to 4)=1', '5 to 10=2', 'More than 10=3'], ['Health']);
q('Are children or elderly people among them?', 0,
  ['No=0', 'Yes=2'], ['Health']);
q('Did it start suddenly in the same place (smoke, gas, dust)?', 2,
  ['No, gradually=1', 'Yes, suddenly=3'], ['Health:Mass Respiratory Distress']);
q('Is the food source still being served?', 0,
  ['No=0', 'Yes=2'], ['Health:Severe Food Poisoning']);
q('Is the water source still being used?', 0,
  ['No=0', 'Yes=2'], ['Health:Water Contamination Illness']);
q('Is it in a school, daycare or workplace?', 0,
  ['No=0', 'Yes=2'], ['Health:Infectious Disease Cluster']);
q('Any dengue warning signs (bleeding, severe stomach pain, persistent vomiting)?', 2,
  ['No=0', 'Yes=3'], ['Health:Dengue Outbreak / Cluster']);

return ['base_major' => $BASE_MAJOR, 'questions' => $QUESTIONS];
