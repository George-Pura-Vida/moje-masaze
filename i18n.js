import sk from './i18n-sk.js';
import en from './i18n-en.js';
import es from './i18n-es.js';
import zh from './i18n-zh.js';
export const groups={
nav:'brandTag navServices navPricing navAbout navProcess navGifts navContact navBook',
hero:'heroEyebrow heroTitle heroText heroButton heroExplore heroNote benefit1 benefit2 benefit3 benefit4',
serviceHeader:'servicesEyebrow servicesTitle servicesLead tagRelax tagWarm tagSense tagFocus tagSport',
services:'s1 s1d s2 s2d s3 s3d s4 s4d s5 s5d s6 s6d s7 s7d s8 s8d',
pricing:'pricingEyebrow pricingTitle pricingLead p1tag p1title p1desc pButton p2tag p2title p2desc bundleEyebrow bundleTitle bundleText bundleOne',
about:'aboutPhotoCaption aboutEyebrow aboutTitle aboutP1 aboutP2 verifiedTitle verifiedText registry',
steps:'stepsEyebrow stepsTitle stepsLead step1 step1d step2 step2d step3 step3d healthNote',
gifts:'giftEyebrow giftTitle giftText giftButton',
bookingOne:'bookEyebrow bookTitle bookLead choose orderType typeBooking typeGift massageFocus focusHolistic focusSensual focusBack focusSport focusHead focusStones focusTowels focusTouch duration duration60 duration180 count count1 count2 count3 totalPrice',
bookingTwo:'contactDetails name email phone recipient prefDate prefTime timeAny note noMedical agreeText privacyLink submit sideEyebrow sideTitle sideText sideOne sideTwo sideThree',
payment:'paymentEyebrow paymentHeading paymentLead payAmount payAccount payVs payStatus pending copy check qrNote',
contact:'contactEyebrow contactTitle contactDesc footerPrivacy footerTop'
};
export const translations={cs:{}};
for(const [lang,src] of Object.entries({sk,en,es,zh})){
 const out={};
 for(const [group,names] of Object.entries(groups)){
   const keys=names.split(' '),values=(src[group]||'').split('|');
   if(keys.length!==values.length)throw new Error('Translation count mismatch: '+lang+'/'+group+': '+keys.length+'/'+values.length);
   keys.forEach((key,i)=>{out[key]=values[i]});
 }
 translations[lang]={...out,...src.misc};
}
